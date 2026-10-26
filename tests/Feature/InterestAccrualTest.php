<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\InDuplumTracking;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanLedgerEntry;
use App\Models\LoanProduct;
use App\Services\Financial\InDuplumEnforcementService;
use App\Services\Financial\PaymentWaterfallService;
use App\Services\Servicing\InterestAccrualService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function accLoan(string $principal = '20000.00', LoanStatus $status = LoanStatus::DISBURSED, string $disbursedAt = '2026-10-01 10:00:00'): Loan
{
    static $n = 0;
    $n++;

    $borrower = Borrower::create([
        'full_name'      => 'Acc Borrower ' . $n,
        'id_number'      => '5500' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        'phone_number'   => '2547660' . str_pad((string) $n, 5, '0', STR_PAD_LEFT),
        'credit_limit'   => '50000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::firstOrCreate(['product_code' => 'ACC-30'], [
        'name'                        => 'Acc Product',
        'min_principal'               => '1000.00',
        'max_principal'               => '50000.00',
        'default_tenure_days'         => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active'                   => true,
    ]);

    $application = LoanApplication::create([
        'application_number'    => 'APP-ACC-' . $n,
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => $product->id,
        'requested_principal'   => $principal,
        'requested_tenure_days' => 30,
        'status'                => 'approved',
    ]);

    $start = CarbonImmutable::parse($disbursedAt, 'Africa/Nairobi');

    $loan = Loan::create([
        'loan_number'          => 'LN-ACC-' . $n,
        'loan_application_id'  => $application->id,
        'borrower_id'          => $borrower->id,
        'loan_product_id'      => $product->id,
        'principal_disbursed'  => $principal,
        'annual_interest_rate' => '18.00',
        'tenure_days'          => 30,
        'status'               => $status,
        'disbursed_at'         => $start,
        'due_date'             => $start->addDays(30),
        'is_in_duplum_locked'  => false,
    ]);

    LoanLedgerEntry::create([
        'loan_id'                   => $loan->id,
        'entry_type'                => 'disbursement',
        'debit'                     => $principal,
        'credit'                    => '0.00',
        'running_principal_balance' => $principal,
        'running_interest_balance'  => '0.00',
        'running_fees_balance'      => '0.00',
        'total_running_balance'     => $principal,
        'reference'                 => 'ACC-DISB-' . $n,
    ]);

    return $loan;
}

function accInterestSum(Loan $loan): string
{
    $total = '0.00';

    foreach (LoanLedgerEntry::query()->where('loan_id', $loan->id)->where('entry_type', 'INTEREST_ACCRUAL')->get() as $row) {
        $total = bcadd($total, (string) $row->debit, 2);
    }

    return $total;
}

function accDay(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date . ' 09:00:00', 'Africa/Nairobi');
}

it('adds interest in steps and reaches the contract total on day 30', function (): void {
    $loan = accLoan();
    $service = app(InterestAccrualService::class);

    expect($service->accrueLoan($loan, accDay('2026-10-11')))->toBe('98.63')
        ->and($service->accrueLoan($loan, accDay('2026-10-31')))->toBe('197.26')
        ->and(accInterestSum($loan))->toBe('295.89');

    $latest = LoanLedgerEntry::query()->where('loan_id', $loan->id)->latest('id')->first();

    expect((string) $latest->running_interest_balance)->toBe('295.89')
        ->and((string) $latest->total_running_balance)->toBe('20295.89');
});

it('adds nothing when run twice on the same day', function (): void {
    $loan = accLoan();
    $service = app(InterestAccrualService::class);

    $service->accrueLoan($loan, accDay('2026-10-11'));

    expect($service->accrueLoan($loan, accDay('2026-10-11')))->toBe('0.00')
        ->and(LoanLedgerEntry::query()->where('entry_type', 'INTEREST_ACCRUAL')->count())->toBe(1);
});

it('never goes past the contract total before default', function (): void {
    $loan = accLoan();
    $service = app(InterestAccrualService::class);

    expect($service->accrueLoan($loan, accDay('2026-11-30')))->toBe('295.89')
        ->and($service->accrueLoan($loan, accDay('2026-12-30')))->toBe('0.00');
});

it('skips loans that are settled', function (): void {
    $loan = accLoan(status: LoanStatus::SETTLED);

    expect(app(InterestAccrualService::class)->accrueLoan($loan, accDay('2026-10-31')))->toBe('0.00')
        ->and(LoanLedgerEntry::query()->where('entry_type', 'INTEREST_ACCRUAL')->count())->toBe(0);
});

it('counts interest on a defaulted loan against the in-duplum cap', function (): void {
    $loan = accLoan('10000.00', LoanStatus::DISBURSED, '2026-01-01 10:00:00');
    app(InDuplumEnforcementService::class)->snapshotDefaultPrincipal($loan);

    $posted = app(InterestAccrualService::class)->accrueLoan($loan, accDay('2026-10-01'));
    $tracking = InDuplumTracking::query()->where('loan_id', $loan->id)->first();
    $latest = LoanLedgerEntry::query()->where('loan_id', $loan->id)->latest('id')->first();

    expect($posted)->toBe('1346.30')
        ->and((string) $tracking->total_post_default_interest)->toBe('1346.30')
        ->and((string) $latest->running_interest_balance)->toBe('1346.30');
});

it('stops at the in-duplum cap and freezes the loan', function (): void {
    $loan = accLoan('1000.00', LoanStatus::DISBURSED, '2026-01-01 10:00:00');
    $engine = app(InDuplumEnforcementService::class);
    $engine->snapshotDefaultPrincipal($loan);
    $engine->recordPostDefaultAccrual($loan, '990.00', 'interest');

    $service = app(InterestAccrualService::class);

    expect($service->accrueLoan($loan, accDay('2026-10-01')))->toBe('10.00')
        ->and($loan->fresh()->is_in_duplum_locked)->toBeTrue()
        ->and($service->accrueLoan($loan, accDay('2026-10-02')))->toBe('0.00');
});

it('lets a full repayment clear the loan and mark the instalment paid', function (): void {
    $loan = accLoan();

    AmortizationSchedule::create([
        'loan_id'               => $loan->id,
        'installment_number'    => 1,
        'due_date'              => '2026-10-31',
        'principal_due'         => '20000.00',
        'interest_due'          => '295.89',
        'fees_due'              => '0.00',
        'total_installment_due' => '20295.89',
        'status'                => 'pending',
    ]);

    app(InterestAccrualService::class)->accrueLoan($loan, accDay('2026-10-31'));

    $result = app(PaymentWaterfallService::class)->executeWaterfall($loan, '20295.89', 'ACC-PAY-1');

    expect($result['allocated_interest'])->toBe('295.89')
        ->and($result['is_settled'])->toBeTrue()
        ->and($loan->fresh()->status)->toBe(LoanStatus::SETTLED)
        ->and(AmortizationSchedule::query()->where('loan_id', $loan->id)->first()->status)->toBe('paid');
});

it('runs from the artisan command', function (): void {
    $loan = accLoan('20000.00', LoanStatus::DISBURSED, CarbonImmutable::now('Africa/Nairobi')->subDays(10)->format('Y-m-d H:i:s'));

    $this->artisan('loans:accrue-interest')
        ->assertSuccessful()
        ->expectsOutputToContain('Interest accrual complete');

    expect(accInterestSum($loan))->toBe('98.63');
});