<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Services\Financial\LoanLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('records sequential immutable ledger entries and calculates running balances accurately', function () {
    /** @var Borrower $borrower */
    $borrower = Borrower::create([
        'full_name' => 'Grace Wambui',
        'id_number' => '31092834',
        'phone_number' => '254712009988',
        'credit_limit' => '30000.00',
        'risk_band' => \App\Enums\RiskBand::B,
        'is_blacklisted' => false,
    ]);

    /** @var LoanProduct $product */
    $product = LoanProduct::create([
        'name' => '30-Day Working Capital',
        'product_code' => 'WC_30',
        'min_principal' => '1000.00',
        'max_principal' => '50000.00',
        'default_tenure_days' => 30,
        'interest_calculation_method' => 'FLAT_RATE',
        'is_active' => true,
    ]);

    /** @var LoanApplication $application */
    $application = LoanApplication::create([
        'application_number' => 'APP_TEST_101',
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'requested_principal' => '10000.00',
        'requested_tenure_days' => 30,
        'status' => 'approved',
    ]);

    /** @var Loan $loan */
    $loan = Loan::create([
        'loan_number' => 'LN_TEST_101',
        'loan_application_id' => $application->id,
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'principal_disbursed' => '10000.00',
        'annual_interest_rate' => '12.00',
        'tenure_days' => 30,
        'status' => \App\Enums\LoanStatus::DISBURSED,
        'is_in_duplum_locked' => false,
    ]);

    /** @var LoanLedgerService $service */
    $service = $this->app->make(LoanLedgerService::class);

    // 1. Initial Disbursement Entry
    $entry1 = $service->recordEntry(
        $loan->id,
        'DISBURSEMENT_PRINCIPAL',
        '10000.00',
        '0.00',
        'DISB_REF_001',
        'Initial principal disbursement'
    );

    expect($entry1->running_principal_balance)->toBe('10000.00')
        ->and($entry1->running_interest_balance)->toBe('0.00')
        ->and($entry1->running_fees_balance)->toBe('0.00')
        ->and($entry1->total_running_balance)->toBe('10000.00');

    // 2. Accrue Interest
    $entry2 = $service->recordEntry(
        $loan->id,
        'INTEREST_ACCRUAL',
        '1200.00',
        '0.00',
        'INT_REF_001',
        'Monthly interest accrual'
    );

    expect($entry2->running_principal_balance)->toBe('10000.00')
        ->and($entry2->running_interest_balance)->toBe('1200.00')
        ->and($entry2->total_running_balance)->toBe('11200.00');

    // 3. Charge Late Fee
    $entry3 = $service->recordEntry(
        $loan->id,
        'FEE_CHARGED',
        '500.00',
        '0.00',
        'FEE_REF_001',
        'Late fee assessment'
    );

    expect($entry3->running_fees_balance)->toBe('500.00')
        ->and($entry3->total_running_balance)->toBe('11700.00');

    // 4. Repayment Credit against Interest
    $entry4 = $service->recordEntry(
        $loan->id,
        'REPAYMENT_INTEREST_CREDIT',
        '0.00',
        '1200.00',
        'PAY_REF_001',
        'Interest payment received via M-Pesa'
    );

    expect($entry4->running_interest_balance)->toBe('0.00')
        ->and($entry4->total_running_balance)->toBe('10500.00');

    $this->assertDatabaseCount('loan_ledger_entries', 4);
});