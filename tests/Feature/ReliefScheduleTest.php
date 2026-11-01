<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanLedgerEntry;
use App\Models\LoanProduct;
use App\Services\Servicing\AmortizationScheduleService;
use App\Services\Servicing\OverdueDetectionService;
use App\Services\Servicing\RestructuringEngine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00', 'Africa/Nairobi'));
});

function rlsLoan(): Loan
{
    $borrower = Borrower::create([
        'full_name' => 'Rls Tester', 'id_number' => '36000001', 'phone_number' => '254755000701',
        'credit_limit' => '50000.00', 'risk_band' => RiskBand::B, 'is_blacklisted' => false,
    ]);

    $product = LoanProduct::create([
        'name' => 'Rls Product', 'product_code' => 'RLS-30', 'min_principal' => '1000.00',
        'max_principal' => '50000.00', 'default_tenure_days' => 30,
        'interest_calculation_method' => 'reducing_balance', 'is_active' => true,
    ]);

    $application = LoanApplication::create([
        'application_number' => 'APP-RLS-01', 'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id, 'requested_principal' => '20000.00',
        'requested_tenure_days' => 30, 'status' => 'approved',
    ]);

    $loan = Loan::create([
        'loan_number' => 'LN-RLS-01', 'loan_application_id' => $application->id,
        'borrower_id' => $borrower->id, 'loan_product_id' => $product->id,
        'principal_disbursed' => '20000.00', 'annual_interest_rate' => '18.00', 'tenure_days' => 30,
        'status' => LoanStatus::DISBURSED, 'disbursed_at' => CarbonImmutable::now()->subDays(35),
        'due_date' => CarbonImmutable::now()->subDays(5), 'is_in_duplum_locked' => false,
    ]);

    $rows = [
        ['disbursement', '20000.00', '0.00', '20000.00', '0.00', '20000.00'],
        ['repayment', '0.00', '10000.00', '10000.00', '0.00', '10000.00'],
        ['penalty_fee', '1000.00', '0.00', '10000.00', '1000.00', '11000.00'],
    ];

    foreach ($rows as [$type, $debit, $credit, $principal, $fees, $total]) {
        LoanLedgerEntry::create([
            'loan_id' => $loan->id, 'entry_type' => $type, 'debit' => $debit, 'credit' => $credit,
            'running_principal_balance' => $principal, 'running_interest_balance' => '0.00',
            'running_fees_balance' => $fees, 'total_running_balance' => $total, 'reference' => 'RLS-' . $type,
        ]);
    }

    AmortizationSchedule::create([
        'loan_id' => $loan->id, 'installment_number' => 1,
        'due_date' => CarbonImmutable::now()->subDays(5)->toDateString(),
        'principal_due' => '20000.00', 'interest_due' => '0.00', 'fees_due' => '0.00',
        'total_installment_due' => '20000.00', 'status' => 'pending',
    ]);

    return $loan;
}

function rlsAccept(Loan $loan): Loan
{
    $engine = app(RestructuringEngine::class);

    return $engine->acceptProposal($engine->generateProposal($loan, tenureExtensionDays: 30));
}

it('replaces the unpaid instalment with the relief plan and keeps the old row', function (): void {
    $loan = rlsLoan();

    $restructured = rlsAccept($loan);

    $rows = AmortizationSchedule::query()->where('loan_id', $loan->id)->orderBy('installment_number')->get();

    expect($rows)->toHaveCount(3)
        ->and($rows[0]->status)->toBe('superseded')
        ->and($rows[1]->installment_number)->toBe(2)
        ->and($rows[1]->due_date->toDateString())->toBe('2026-11-10')
        ->and((string) $rows[1]->total_installment_due)->toBe('5000.00')
        ->and((string) $rows[1]->principal_due)->toBe('5000.00')
        ->and($rows[2]->due_date->toDateString())->toBe('2026-11-25')
        ->and((string) $rows[2]->total_installment_due)->toBe('5000.00')
        ->and($rows[1]->status)->toBe('pending')
        ->and($restructured->fresh()->due_date->toDateString())->toBe('2026-11-25');
});

it('is no longer overdue once the relief plan is in place', function (): void {
    $loan = rlsLoan();
    $detector = app(OverdueDetectionService::class);

    expect($detector->daysPastDue($loan))->toBe(5);

    rlsAccept($loan);

    expect($detector->daysPastDue($loan->fresh()))->toBe(0);
});

it('applies repayments to the relief instalments and never to superseded ones', function (): void {
    $loan = rlsLoan();
    rlsAccept($loan);

    app(AmortizationScheduleService::class)->applyPayment($loan->id, '0.00', '0.00', '3000.00');

    $rows = AmortizationSchedule::query()->where('loan_id', $loan->id)->orderBy('installment_number')->get();

    expect($rows[0]->status)->toBe('superseded')
        ->and((string) $rows[0]->principal_paid)->toBe('0.00')
        ->and((string) $rows[1]->principal_paid)->toBe('3000.00')
        ->and($rows[1]->status)->toBe('partial')
        ->and($rows[2]->status)->toBe('pending');
});