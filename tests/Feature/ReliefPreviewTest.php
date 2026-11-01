<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanLedgerEntry;
use App\Models\LoanProduct;
use App\Services\Customer\BorrowerPortalService;
use App\Services\Servicing\RestructuringEngine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function rpvLoan(bool $withFee): Loan
{
    $borrower = Borrower::create([
        'full_name' => 'Rpv Tester', 'id_number' => '37000001', 'phone_number' => '254755000801',
        'credit_limit' => '50000.00', 'risk_band' => RiskBand::B, 'is_blacklisted' => false,
    ]);

    $product = LoanProduct::create([
        'name' => 'Rpv Product', 'product_code' => 'RPV-30', 'min_principal' => '1000.00',
        'max_principal' => '50000.00', 'default_tenure_days' => 30,
        'interest_calculation_method' => 'reducing_balance', 'is_active' => true,
    ]);

    $application = LoanApplication::create([
        'application_number' => 'APP-RPV-01', 'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id, 'requested_principal' => '20000.00',
        'requested_tenure_days' => 30, 'status' => 'approved',
    ]);

    $loan = Loan::create([
        'loan_number' => 'LN-RPV-01', 'loan_application_id' => $application->id,
        'borrower_id' => $borrower->id, 'loan_product_id' => $product->id,
        'principal_disbursed' => '20000.00', 'annual_interest_rate' => '18.00', 'tenure_days' => 30,
        'status' => LoanStatus::DISBURSED, 'disbursed_at' => CarbonImmutable::now()->subDays(35),
        'due_date' => CarbonImmutable::now()->subDays(5), 'is_in_duplum_locked' => false,
    ]);

    $fee = $withFee ? '250.00' : '0.00';

    $rows = [
        ['disbursement', '20000.00', '0.00', '20000.00', '0.00', '20000.00'],
        ['FEE_CHARGED', $fee, '0.00', '20000.00', $fee, bcadd('20000.00', $fee, 2)],
        ['repayment', '0.00', '10000.00', '10000.00', $fee, bcadd('10000.00', $fee, 2)],
        ['PENALTY_CHARGED', '1000.00', '0.00', '10000.00', bcadd($fee, '1000.00', 2), bcadd(bcadd('10000.00', $fee, 2), '1000.00', 2)],
    ];

    foreach ($rows as [$type, $debit, $credit, $principal, $fees, $total]) {
        LoanLedgerEntry::create([
            'loan_id' => $loan->id, 'entry_type' => $type, 'debit' => $debit, 'credit' => $credit,
            'running_principal_balance' => $principal, 'running_interest_balance' => '0.00',
            'running_fees_balance' => $fees, 'total_running_balance' => $total, 'reference' => 'RPV-' . $type,
        ]);
    }

    return $loan;
}

it('keeps the processing fee in the preview and removes only the penalty', function (): void {
    $loan = rpvLoan(true);
    $portal = app(BorrowerPortalService::class);

    $preview = $portal->reliefPreview($loan->borrower, $portal->balances($loan), $loan);

    expect($preview['owed_today'])->toBe('11250.00')
        ->and($preview['waived'])->toBe('1000.00')
        ->and($preview['kept_fee'])->toBe('250.00')
        ->and($preview['owed_after'])->toBe('10250.00')
        ->and($preview['first_amount'])->toBe('5125.00')
        ->and($preview['second_amount'])->toBe('5125.00');
});

it('shows the same two payments the engine saves', function (): void {
    $loan = rpvLoan(true);
    $portal = app(BorrowerPortalService::class);

    $preview = $portal->reliefPreview($loan->borrower, $portal->balances($loan), $loan);
    $proposal = app(RestructuringEngine::class)->generateProposal($loan, tenureExtensionDays: 30);
    $amounts = $proposal->schedules()->orderBy('installment_number')->pluck('installment_amount')->map(fn ($a): string => (string) $a)->all();

    expect($amounts)->toBe([$preview['first_amount'], $preview['second_amount']]);
});

it('removes the whole fees balance when no processing fee was ever charged', function (): void {
    $loan = rpvLoan(false);
    $portal = app(BorrowerPortalService::class);

    $preview = $portal->reliefPreview($loan->borrower, $portal->balances($loan), $loan);

    expect($preview['kept_fee'])->toBe('0.00')
        ->and($preview['waived'])->toBe('1000.00')
        ->and($preview['owed_after'])->toBe('10000.00');
});