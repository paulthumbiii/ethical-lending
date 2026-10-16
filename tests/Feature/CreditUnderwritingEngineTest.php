<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RiskBand;
use App\Models\Borrower;
use App\Models\BorrowerFinancialProfile;
use App\Models\CrbInquiryRecord;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Services\Underwriting\CreditUnderwritingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('approves application and assigns risk band A with elevated limit for high credit score', function () {
    /** @var Borrower $borrower */
    $borrower = Borrower::create([
        'full_name' => 'Dr. Mercy Chebet',
        'id_number' => '29810294',
        'phone_number' => '254711889900',
        'credit_limit' => '0.00',
        'risk_band' => RiskBand::C,
        'is_blacklisted' => false,
    ]);

    BorrowerFinancialProfile::create([
        'borrower_id' => $borrower->id,
        'monthly_income' => '150000.00',
        'monthly_expenses' => '40000.00',
        'mpesa_average_monthly_inflow' => '120000.00',
        'existing_debt_obligations' => '10000.00',
    ]);

    CrbInquiryRecord::create([
        'borrower_id' => $borrower->id,
        'bureau_name' => 'METROPOL_CRB',
        'credit_score' => 780,
        'open_accounts_count' => 2,
        'defaulted_accounts_count' => 0,
        'total_npl_amount' => '0.00',
        'inquiry_reference' => 'CRB_REF_99812',
    ]);

    /** @var LoanProduct $product */
    $product = LoanProduct::create([
        'name' => 'SME Working Capital',
        'product_code' => 'SME_WC',
        'min_principal' => '5000.00',
        'max_principal' => '100000.00',
        'default_tenure_days' => 30,
        'interest_calculation_method' => 'FLAT_RATE',
        'is_active' => true,
    ]);

    /** @var LoanApplication $application */
    $application = LoanApplication::create([
        'application_number' => 'APP_TEST_001',
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'requested_principal' => '20000.00',
        'requested_tenure_days' => 30,
        'status' => 'pending',
    ]);

    /** @var CreditUnderwritingEngine $engine */
    $engine = $this->app->make(CreditUnderwritingEngine::class);

    $result = $engine->evaluateApplication($application->id);

    expect($result['status'])->toBe('approved_pending_checker')
        ->and($result['risk_band'])->toBe('A')
        ->and($result['approved_limit'])->toBe('25000.00') // 125% of 20,000.00
        ->and($result['rules_passed'])->toBe(4)
        ->and($result['rules_failed'])->toBe(0);

    $this->assertDatabaseHas('loan_applications', [
        'id' => $application->id,
        'status' => 'approved_pending_checker',
    ]);

    $this->assertDatabaseHas('credit_rule_evaluations', [
        'loan_application_id' => $application->id,
        'rule_identifier' => 'RULE_01_BLACKLIST_CHECK',
        'status' => 'PASSED',
    ]);
});

test('rejects application when CRB defaults are present and assigns Risk Band D', function () {
    /** @var Borrower $borrower */
    $borrower = Borrower::create([
        'full_name' => 'Samuel Omondi',
        'id_number' => '10928374',
        'phone_number' => '254700112233',
        'credit_limit' => '0.00',
        'risk_band' => RiskBand::C,
        'is_blacklisted' => false,
    ]);

    BorrowerFinancialProfile::create([
        'borrower_id' => $borrower->id,
        'monthly_income' => '80000.00',
        'monthly_expenses' => '30000.00',
        'mpesa_average_monthly_inflow' => '50000.00',
        'existing_debt_obligations' => '5000.00',
    ]);

    CrbInquiryRecord::create([
        'borrower_id' => $borrower->id,
        'bureau_name' => 'TRANSUNION_CRB',
        'credit_score' => 510,
        'open_accounts_count' => 3,
        'defaulted_accounts_count' => 2, // Defaulted accounts trigger failure
        'total_npl_amount' => '45000.00',
        'inquiry_reference' => 'CRB_REF_77123',
    ]);

    /** @var LoanProduct $product */
    $product = LoanProduct::create([
        'name' => 'Personal Salary Advance',
        'product_code' => 'SAL_ADV',
        'min_principal' => '1000.00',
        'max_principal' => '50000.00',
        'default_tenure_days' => 15,
        'interest_calculation_method' => 'FLAT_RATE',
        'is_active' => true,
    ]);

    /** @var LoanApplication $application */
    $application = LoanApplication::create([
        'application_number' => 'APP_TEST_002',
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'requested_principal' => '15000.00',
        'requested_tenure_days' => 15,
        'status' => 'pending',
    ]);

    /** @var CreditUnderwritingEngine $engine */
    $engine = $this->app->make(CreditUnderwritingEngine::class);

    $result = $engine->evaluateApplication($application->id);

    expect($result['status'])->toBe('rejected')
        ->and($result['risk_band'])->toBe('D')
        ->and($result['approved_limit'])->toBe('0.00')
        ->and($result['rules_failed'])->toBeGreaterThanOrEqual(1);

    $this->assertDatabaseHas('loan_applications', [
        'id' => $application->id,
        'status' => 'rejected',
    ]);
});