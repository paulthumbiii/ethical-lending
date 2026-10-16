<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Exceptions\Fintech\InvalidPaymentAmountException;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanLedgerEntry;
use App\Models\LoanProduct;
use App\Models\OutboxEvent;
use App\Services\Financial\PaymentWaterfallService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->borrower = Borrower::create([
        'full_name'      => 'Wanjiku Kamau',
        'id_number'      => '28471928',
        'phone_number'   => '254712345678',
        'credit_limit'   => '50000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);

    $this->product = LoanProduct::create([
        'name'                        => 'Biashara Micro Capital',
        'product_code'                => 'BMC-30',
        'min_principal'               => '1000.00',
        'max_principal'               => '50000.00',
        'default_tenure_days'         => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active'                   => true,
    ]);

    $this->application = LoanApplication::create([
        'application_number'   => 'APP-WATERFALL-01',
        'borrower_id'          => $this->borrower->id,
        'loan_product_id'      => $this->product->id,
        'requested_principal'  => '10000.00',
        'requested_tenure_days'=> 30,
        'status'               => 'approved',
    ]);

    $this->loan = Loan::create([
        'loan_number'         => 'LN-WATERFALL-01',
        'loan_application_id' => $this->application->id,
        'borrower_id'         => $this->borrower->id,
        'loan_product_id'     => $this->product->id,
        'principal_disbursed' => '10000.00',
        'annual_interest_rate'=> '18.00',
        'tenure_days'         => 30,
        'status'              => LoanStatus::DISBURSED,
        'disbursed_at'        => CarbonImmutable::now()->subDays(15),
        'due_date'            => CarbonImmutable::now()->addDays(15),
        'is_in_duplum_locked' => false,
    ]);

    // Initial Ledger state: KES 10,000 principal, KES 1,500 interest accrued, KES 500 penalty fees
    // Total debt = KES 12,000.00
    LoanLedgerEntry::create([
        'loan_id'                   => $this->loan->id,
        'entry_type'                => 'accrual_baseline',
        'debit'                     => '12000.00',
        'credit'                    => '0.00',
        'running_principal_balance' => '10000.00',
        'running_interest_balance'  => '1500.00',
        'running_fees_balance'      => '500.00',
        'total_running_balance'     => '12000.00',
        'reference'                 => 'INIT-DEBT',
        'notes'                     => 'Initial debt baseline setup for waterfall test',
    ]);

    $this->service = app(PaymentWaterfallService::class);
});

it('allocates partial repayment to fees first then interest, leaving principal intact', function (): void {
    // Borrower pays KES 1,000.00
    // Should clear KES 500 fees entirely, remaining KES 500 to interest, KES 0 to principal
    $result = $this->service->executeWaterfall($this->loan, '1000.00', 'MPESA-TXN-01');

    expect($result['allocated_fees'])->toBe('500.00')
        ->and($result['allocated_interest'])->toBe('500.00')
        ->and($result['allocated_principal'])->toBe('0.00')
        ->and($result['overpayment_credit'])->toBe('0.00')
        ->and($result['new_total_balance'])->toBe('11000.00')
        ->and($result['is_settled'])->toBeFalse();

    // Verify ledger entry
    $latest = LoanLedgerEntry::where('loan_id', $this->loan->id)->latest('id')->first();
    expect($latest->running_fees_balance)->toBe('0.00')
        ->and($latest->running_interest_balance)->toBe('1000.00')
        ->and($latest->running_principal_balance)->toBe('10000.00');
});

it('cascades sequential payments through interest down into principal', function (): void {
    // First payment: KES 1,000 (clears 500 fee, 500 interest)
    $this->service->executeWaterfall($this->loan, '1000.00', 'MPESA-TXN-01');

    // Second payment: KES 3,000
    // Fees are 0. Remaining interest is KES 1,000 (cleared). Remaining KES 2,000 goes to principal!
    $result = $this->service->executeWaterfall($this->loan, '3000.00', 'MPESA-TXN-02');

    expect($result['allocated_fees'])->toBe('0.00')
        ->and($result['allocated_interest'])->toBe('1000.00')
        ->and($result['allocated_principal'])->toBe('2000.00')
        ->and($result['new_total_balance'])->toBe('8000.00')
        ->and($result['is_settled'])->toBeFalse();

    $latest = LoanLedgerEntry::where('loan_id', $this->loan->id)->latest('id')->first();
    expect($latest->running_fees_balance)->toBe('0.00')
        ->and($latest->running_interest_balance)->toBe('0.00')
        ->and($latest->running_principal_balance)->toBe('8000.00');
});

it('fully settles loan and emits an outbox event when entire debt is cleared', function (): void {
    // Pay exact total balance of KES 12,000.00
    $result = $this->service->executeWaterfall($this->loan, '12000.00', 'MPESA-FULL-SETTLE');

    expect($result['allocated_fees'])->toBe('500.00')
        ->and($result['allocated_interest'])->toBe('1500.00')
        ->and($result['allocated_principal'])->toBe('10000.00')
        ->and($result['new_total_balance'])->toBe('0.00')
        ->and($result['is_settled'])->toBeTrue();

    $this->loan->refresh();
    expect($this->loan->status)->toBe(LoanStatus::SETTLED);

    // Verify outbox settlement event
    $outbox = OutboxEvent::where('aggregate_id', $this->loan->id)
        ->where('event_name', 'loan.settled')
        ->first();

    expect($outbox)->not->toBeNull()
        ->and($outbox->payload['status'])->toBe('SETTLED');
});

it('accurately captures overpayment credits when payment exceeds total debt', function (): void {
    // Debt is KES 12,000.00. Borrower pays KES 14,500.00
    $result = $this->service->executeWaterfall($this->loan, '14500.00', 'MPESA-OVERPAY-01');

    expect($result['allocated_fees'])->toBe('500.00')
        ->and($result['allocated_interest'])->toBe('1500.00')
        ->and($result['allocated_principal'])->toBe('10000.00')
        ->and($result['overpayment_credit'])->toBe('2500.00')
        ->and($result['new_total_balance'])->toBe('0.00')
        ->and($result['is_settled'])->toBeTrue();

    // Verify waterfall log records overpayment
    $log = DB::table('payment_waterfall_logs')
        ->where('payment_reference', 'MPESA-OVERPAY-01')
        ->first();

    // Format float from SQLite/MySQL to exact 2-decimal string
    expect(number_format((float) $log->overpayment_amount, 2, '.', ''))->toBe('2500.00');
});

it('throws InvalidPaymentAmountException on zero or negative amounts', function (): void {
    $this->service->executeWaterfall($this->loan, '0.00', 'BAD-PAYMENT');
})->throws(InvalidPaymentAmountException::class);