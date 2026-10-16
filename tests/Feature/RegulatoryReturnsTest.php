<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LoanStatus;
use App\Models\Borrower;
use App\Models\CrbDisputeLock;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Services\Compliance\CbkStatutoryReturnService;
use App\Services\Compliance\CrbMetro2ExporterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('generates monthly cbk statutory return with portfolio metrics', function () {
    /** @var Borrower $borrower */
    $borrower = Borrower::create([
        'full_name' => 'Peter Njuguna',
        'id_number' => '30192834',
        'phone_number' => '254722001122',
        'credit_limit' => '50000.00',
        'risk_band' => \App\Enums\RiskBand::A,
        'is_blacklisted' => false,
    ]);

    /** @var LoanProduct $product */
    $product = LoanProduct::create([
        'name' => 'SME Capital',
        'product_code' => 'SME_CAP',
        'min_principal' => '1000.00',
        'max_principal' => '100000.00',
        'default_tenure_days' => 30,
        'interest_calculation_method' => 'FLAT_RATE',
        'is_active' => true,
    ]);

    /** @var LoanApplication $app */
    $app = LoanApplication::create([
        'application_number' => 'APP_REG_001',
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'requested_principal' => '25000.00',
        'requested_tenure_days' => 30,
        'status' => 'approved',
    ]);

    Loan::create([
        'loan_number' => 'LN_REG_001',
        'loan_application_id' => $app->id,
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'principal_disbursed' => '25000.00',
        'annual_interest_rate' => '15.00',
        'tenure_days' => 30,
        'status' => LoanStatus::DISBURSED,
        'is_in_duplum_locked' => false,
    ]);

    /** @var CbkStatutoryReturnService $service */
    $service = $this->app->make(CbkStatutoryReturnService::class);

    $return = $service->generateMonthlyReturn('2026-10');

    expect($return->reporting_period)->toBe('2026-10');

    $this->assertDatabaseHas('cbk_regulatory_returns', [
        'reporting_period' => '2026-10',
        'return_type' => 'MONTHLY_PORTFOLIO_HEALTH',
    ]);
});

test('exports metro 2 data while strictly excluding borrowers with active dispute locks', function () {
    /** @var Borrower $borrowerClean */
    $borrowerClean = Borrower::create([
        'full_name' => 'Mary Atieno',
        'id_number' => '28192034',
        'phone_number' => '254711223344',
        'credit_limit' => '20000.00',
        'risk_band' => \App\Enums\RiskBand::B,
        'is_blacklisted' => false,
    ]);

    /** @var Borrower $borrowerDisputed */
    $borrowerDisputed = Borrower::create([
        'full_name' => 'John Kamau',
        'id_number' => '10293847',
        'phone_number' => '254733445566',
        'credit_limit' => '10000.00',
        'risk_band' => \App\Enums\RiskBand::C,
        'is_blacklisted' => false,
    ]);

    /** @var LoanProduct $product */
    $product = LoanProduct::create([
        'name' => 'Micro Loan',
        'product_code' => 'M_LOAN',
        'min_principal' => '1000.00',
        'max_principal' => '20000.00',
        'default_tenure_days' => 14,
        'interest_calculation_method' => 'FLAT_RATE',
        'is_active' => true,
    ]);

    $app1 = LoanApplication::create(['application_number' => 'APP_1', 'borrower_id' => $borrowerClean->id, 'loan_product_id' => $product->id, 'requested_principal' => '5000.00', 'requested_tenure_days' => 14, 'status' => 'approved']);
    $app2 = LoanApplication::create(['application_number' => 'APP_2', 'borrower_id' => $borrowerDisputed->id, 'loan_product_id' => $product->id, 'requested_principal' => '5000.00', 'requested_tenure_days' => 14, 'status' => 'approved']);

    $loanClean = Loan::create(['loan_number' => 'LN_1', 'loan_application_id' => $app1->id, 'borrower_id' => $borrowerClean->id, 'loan_product_id' => $product->id, 'principal_disbursed' => '5000.00', 'annual_interest_rate' => '12.00', 'tenure_days' => 14, 'status' => LoanStatus::DISBURSED, 'is_in_duplum_locked' => false]);
    $loanDisputed = Loan::create(['loan_number' => 'LN_2', 'loan_application_id' => $app2->id, 'borrower_id' => $borrowerDisputed->id, 'loan_product_id' => $product->id, 'principal_disbursed' => '5000.00', 'annual_interest_rate' => '12.00', 'tenure_days' => 14, 'status' => LoanStatus::DEFAULTED, 'is_in_duplum_locked' => false]);

    CrbDisputeLock::create([
        'loan_id' => $loanDisputed->id,
        'bureau_name' => 'METROPOL',
        'dispute_reference' => 'DISP_2026_99',
        'dispute_reason' => 'Contested interest calculation',
        'lock_expires_at' => now()->addDays(30),
        'resolved_at' => null,
    ]);

    /** @var CrbMetro2ExporterService $service */
    $service = $this->app->make(CrbMetro2ExporterService::class);

    $export = $service->exportMetro2Data('METROPOL');

    expect($export->records_count)->toBe(1);

    $this->assertDatabaseHas('crb_report_exports', [
        'bureau_name' => 'METROPOL',
        'records_count' => 1,
    ]);
});

test('artisan command loans:export-regulatory-returns executes successfully', function () {
    $this->artisan('loans:export-regulatory-returns', ['period' => '2026-10'])
        ->assertSuccessful();
});