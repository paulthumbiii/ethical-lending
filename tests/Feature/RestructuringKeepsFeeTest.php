<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanLedgerEntry;
use App\Models\LoanProduct;
use App\Services\Servicing\RestructuringEngine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function rkfLoan(): Loan
{
    $borrower = Borrower::create([
        'full_name' => 'Rkf Tester', 'id_number' => '35000001', 'phone_number' => '254755000601',
        'credit_limit' => '50000.00', 'risk_band' => RiskBand::B, 'is_blacklisted' => false,
    ]);

    $product = LoanProduct::create([
        'name' => 'Rkf Product', 'product_code' => 'RKF-30', 'min_principal' => '1000.00',
        'max_principal' => '50000.00', 'default_tenure_days' => 30,
        'interest_calculation_method' => 'reducing_balance', 'is_active' => true,
    ]);

    $application = LoanApplication::create([
        'application_number' => 'APP-RKF-01', 'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id, 'requested_principal' => '20000.00',
        'requested_tenure_days' => 30, 'status' => 'approved',
    ]);

    $loan = Loan::create([
        'loan_number' => 'LN-RKF-01', 'loan_application_id' => $application->id,
        'borrower_id' => $borrower->id, 'loan_product_id' => $product->id,
        'principal_disbursed' => '20000.00', 'annual_interest_rate' => '18.00', 'tenure_days' => 30,
        'status' => LoanStatus::DISBURSED, 'disbursed_at' => CarbonImmutable::now()->subDays(35),
        'due_date' => CarbonImmutable::now()->subDays(5), 'is_in_duplum_locked' => false,
    ]);

    $rows = [
        ['disbursement', '20000.00', '0.00', '20000.00', '0.00', '20000.00'],
        ['FEE_CHARGED', '250.00', '0.00', '20000.00', '250.00', '20250.00'],
        ['repayment', '0.00', '10000.00', '10000.00', '250.00', '10250.00'],
        ['PENALTY_CHARGED', '1000.00', '0.00', '10000.00', '1250.00', '11250.00'],
    ];

    foreach ($rows as [$type, $debit, $credit, $principal, $fees, $total]) {
        LoanLedgerEntry::create([
            'loan_id' => $loan->id, 'entry_type' => $type, 'debit' => $debit, 'credit' => $credit,
            'running_principal_balance' => $principal, 'running_interest_balance' => '0.00',
            'running_fees_balance' => $fees, 'total_running_balance' => $total, 'reference' => 'RKF-' . $type,
        ]);
    }

    return $loan;
}

it('forgives only the penalty and keeps the processing fee', function () {
    $loan = rkfLoan();
    $engine = app(RestructuringEngine::class);

    $proposal = $engine->generateProposal($loan, tenureExtensionDays: 30);

    expect($proposal->forgiven_penalties)->toBe('1000.00');

    $engine->acceptProposal($proposal);

    $latest = LoanLedgerEntry::where('loan_id', $loan->id)->latest('id')->first();

    expect($latest->entry_type)->toBe('restructure_waiver')
        ->and($latest->credit)->toBe('1000.00')
        ->and($latest->running_fees_balance)->toBe('250.00')
        ->and($latest->total_running_balance)->toBe('10250.00');
});

it('includes the kept fee in the restructured instalments', function () {
    $loan = rkfLoan();

    $proposal = app(RestructuringEngine::class)->generateProposal($loan, tenureExtensionDays: 30);

    $sum = '0.00';
    foreach ($proposal->schedules as $schedule) {
        $sum = bcadd($sum, (string) $schedule->installment_amount, 2);
    }

    expect($sum)->toBe('10250.00');
});