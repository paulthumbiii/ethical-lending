<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Models\Borrower;
use App\Models\CollectionCase;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function cwlCase(): CollectionCase
{
    $borrower = Borrower::create([
        'full_name'      => 'Cwl Borrower',
        'id_number'      => '55009901',
        'phone_number'   => '254766099001',
        'credit_limit'   => '50000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::create([
        'product_code'                => 'CWL-30',
        'name'                        => 'Cwl Product',
        'min_principal'               => '1000.00',
        'max_principal'               => '50000.00',
        'default_tenure_days'         => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active'                   => true,
    ]);

    $application = LoanApplication::create([
        'application_number'    => 'APP-CWL-1',
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => $product->id,
        'requested_principal'   => '10000.00',
        'requested_tenure_days' => 30,
        'status'                => 'approved',
    ]);

    $start = CarbonImmutable::parse('2026-09-01 10:00:00', 'Africa/Nairobi');

    $loan = Loan::create([
        'loan_number'          => 'LN-CWL-1',
        'loan_application_id'  => $application->id,
        'borrower_id'          => $borrower->id,
        'loan_product_id'      => $product->id,
        'principal_disbursed'  => '10000.00',
        'annual_interest_rate' => '18.00',
        'tenure_days'          => 30,
        'status'               => LoanStatus::DISBURSED,
        'disbursed_at'         => $start,
        'due_date'             => $start->addDays(30),
        'is_in_duplum_locked'  => false,
    ]);

    return CollectionCase::create([
        'loan_id'     => $loan->id,
        'borrower_id' => $borrower->id,
        'dpd_bucket'  => 'DPD_1_30',
        'case_status' => 'open',
    ]);
}

it('links each workbench loan number to its case page', function (): void {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00', 'Africa/Nairobi'));
    $this->seed(RolesSeeder::class);

    $user = User::create([
        'name'         => 'Cwl Collector',
        'email'        => 'cwl-collector@ethical-lending.test',
        'phone_number' => '254766099002',
        'password'     => 'Demo@12345',
        'is_active'    => true,
    ]);
    $user->assignRole('collections_officer');

    $case = cwlCase();

    $this->actingAs($user)
        ->get(route('lender.collections.index'))
        ->assertOk()
        ->assertSee('href="' . route('lender.collections.show', $case) . '"', false)
        ->assertSee('LN-CWL-1');
});