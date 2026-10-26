<?php

declare(strict_types=1);

use App\Enums\RiskBand;
use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\ChartOfAccount;
use App\Models\FeeMatrix;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\MpesaB2cDisbursement;
use App\Models\ProductInterestRule;
use App\Models\User;
use App\Services\Financial\Mpesa\B2cDisbursementService;
use App\Services\Financial\PaymentWaterfallService;
use App\Services\Lending\LoanOriginationService;
use App\Services\Servicing\AmortizationScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function amoSetup(int $tenure = 30, bool $fullLedger = true, string $requested = '20000.00'): Loan
{
    ChartOfAccount::create(['account_code' => '1010', 'account_name' => 'M-Pesa Settlement Account', 'account_type' => 'ASSET', 'currency' => 'KES', 'is_active' => true]);
    ChartOfAccount::create(['account_code' => '1200', 'account_name' => 'Loans Receivable', 'account_type' => 'ASSET', 'currency' => 'KES', 'is_active' => true]);

    if ($fullLedger) {
        ChartOfAccount::create(['account_code' => '2030', 'account_name' => 'KRA Excise Duty Payable', 'account_type' => 'LIABILITY', 'currency' => 'KES', 'is_active' => true]);
        ChartOfAccount::create(['account_code' => '4020', 'account_name' => 'Fee Income', 'account_type' => 'REVENUE', 'currency' => 'KES', 'is_active' => true]);
    }

    $user = User::create([
        'name'         => 'Amo Tester',
        'email'        => 'amo-user@ethical-lending.test',
        'password'     => 'Demo@12345',
        'phone_number' => '254700000088',
        'is_active'    => true,
    ]);

    $borrower = Borrower::create([
        'full_name'      => 'Amo Borrower',
        'id_number'      => '43555666',
        'phone_number'   => '254712000444',
        'credit_limit'   => '50000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::create([
        'name'                        => 'Amo Product',
        'product_code'                => 'AMO-30',
        'min_principal'               => '1000.00',
        'max_principal'               => '50000.00',
        'default_tenure_days'         => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active'                   => true,
    ]);

    ProductInterestRule::create([
        'loan_product_id'       => $product->id,
        'tier_name'             => 'Standard',
        'annual_rate'           => '18.00',
        'monthly_rate'          => '1.50',
        'compounding_frequency' => 'none',
    ]);

    FeeMatrix::create([
        'loan_product_id'           => $product->id,
        'fee_name'                  => 'Processing fee',
        'calculation_type'          => 'percentage',
        'fee_amount'                => '0.00',
        'fee_percentage'            => '5.00',
        'is_excise_duty_applicable' => true,
    ]);

    $application = LoanApplication::create([
        'application_number'    => 'APP-AMO-01',
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => $product->id,
        'requested_principal'   => $requested,
        'requested_tenure_days' => $tenure,
        'status'                => 'approved',
    ]);

    return app(LoanOriginationService::class)->originate($application->id, $user->id, '127.0.0.1', 'Pest');
}

function amoComplete(Loan $loan): Loan
{
    MpesaB2cDisbursement::create([
        'loan_id'                    => $loan->id,
        'originator_conversation_id' => 'B2C-AMO-1',
        'conversation_id'            => 'CONV-AMO-1',
        'recipient_phone'            => '254712000444',
        'amount'                     => (string) $loan->principal_disbursed,
        'status'                     => 'initiated',
    ]);

    app(B2cDisbursementService::class)->processCallback([
        'Result' => [
            'ResultCode'               => 0,
            'ResultDesc'               => 'The service request is processed successfully.',
            'OriginatorConversationID' => 'B2C-AMO-1',
            'ConversationID'           => 'CONV-AMO-1',
            'TransactionID'            => 'AMOTXN001',
        ],
    ]);

    return $loan->fresh();
}

/** @return \Illuminate\Support\Collection<int, AmortizationSchedule> */
function amoRows(Loan $loan)
{
    return AmortizationSchedule::query()->where('loan_id', $loan->id)->orderBy('installment_number')->get();
}

it('creates one instalment for a 30-day loan when the money lands', function (): void {
    $loan = amoComplete(amoSetup());
    $rows = amoRows($loan);
    $first = $rows->first();

    expect($rows)->toHaveCount(1)
        ->and((string) $first->principal_due)->toBe('20000.00')
        ->and((string) $first->interest_due)->toBe('295.89')
        ->and((string) $first->fees_due)->toBe('1000.00')
        ->and((string) $first->total_installment_due)->toBe('21295.89')
        ->and($first->status)->toBe('pending')
        ->and($first->due_date->toDateString())->toBe($loan->due_date->toDateString());
});

it('splits a 75-day loan into three instalments that add up exactly', function (): void {
    $loan = amoComplete(amoSetup(tenure: 75));
    $rows = amoRows($loan);

    expect($rows)->toHaveCount(3)
        ->and((string) $rows[0]->total_installment_due)->toBe('7913.23')
        ->and((string) $rows[1]->total_installment_due)->toBe('6913.23')
        ->and((string) $rows[2]->total_installment_due)->toBe('6913.27')
        ->and((string) $rows[1]->fees_due)->toBe('0.00')
        ->and($rows[2]->due_date->toDateString())->toBe($loan->due_date->toDateString());

    $sum = '0.00';
    foreach ($rows as $row) {
        $sum = bcadd($sum, (string) $row->total_installment_due, 2);
    }

    expect($sum)->toBe('21739.73');
});

it('does not create the schedule twice', function (): void {
    $loan = amoComplete(amoSetup());

    app(AmortizationScheduleService::class)->generate($loan);
    app(AmortizationScheduleService::class)->generate($loan);

    expect(amoRows($loan))->toHaveCount(1);
});

it('shows no fee on the schedule when the fee could not be booked', function (): void {
    $loan = amoComplete(amoSetup(fullLedger: false));
    $first = amoRows($loan)->first();

    expect((string) $first->fees_due)->toBe('0.00')
        ->and((string) $first->total_installment_due)->toBe('20295.89');
});

it('marks a partial repayment against the instalment', function (): void {
    $loan = amoComplete(amoSetup());

    app(PaymentWaterfallService::class)->executeWaterfall($loan, '1500.00', 'AMO-PAY-1');

    $first = amoRows($loan)->first();

    expect((string) $first->fees_paid)->toBe('1000.00')
        ->and((string) $first->interest_paid)->toBe('0.00')
        ->and((string) $first->principal_paid)->toBe('500.00')
        ->and($first->status)->toBe('partial');
});

it('fills the oldest instalment first and spills into the next', function (): void {
    $loan = amoComplete(amoSetup(tenure: 75));

    app(PaymentWaterfallService::class)->executeWaterfall($loan, '8000.00', 'AMO-PAY-2');

    $rows = amoRows($loan);

    expect((string) $rows[0]->principal_paid)->toBe('6666.66')
        ->and((string) $rows[0]->fees_paid)->toBe('1000.00')
        ->and((string) $rows[1]->principal_paid)->toBe('333.34')
        ->and($rows[0]->status)->toBe('partial')
        ->and($rows[1]->status)->toBe('partial')
        ->and($rows[2]->status)->toBe('pending');
});

it('marks an instalment paid once everything due on it is covered', function (): void {
    $loan = amoSetup();

    AmortizationSchedule::create([
        'loan_id'               => $loan->id,
        'installment_number'    => 1,
        'due_date'              => now()->addDays(30)->toDateString(),
        'principal_due'         => '80.00',
        'interest_due'          => '10.00',
        'fees_due'              => '10.00',
        'total_installment_due' => '100.00',
        'status'                => 'pending',
    ]);

    app(AmortizationScheduleService::class)->applyPayment($loan->id, '10.00', '10.00', '80.00');

    expect(amoRows($loan)->first()->status)->toBe('paid');
});