<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Enums\UserRole;
use App\Models\Borrower;
use App\Models\InDuplumTracking;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanLedgerEntry;
use App\Models\LoanProduct;
use App\Models\MakerCheckerRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    // Seed Spatie roles using exact PascalCase enum names
    Role::findOrCreate(UserRole::LenderAdmin->value);
    Role::findOrCreate(UserRole::CreditOfficer->value);
    Role::findOrCreate(UserRole::Borrower->value);

    // Create staff user
    $this->staffUser = User::create([
        'name'         => 'Faith Checker',
        'email'        => 'faith@ethical-lending.test',
        'phone_number' => '254711000111',
        'password'     => bcrypt('Password123'),
        'is_active'    => true,
    ]);
    $this->staffUser->assignRole(UserRole::LenderAdmin->value);

    // Create borrower user
    $this->borrowerUser = User::create([
        'name'         => 'Wanjiku Kamau',
        'email'        => 'wanjiku@ethical-lending.test',
        'phone_number' => '254722000222',
        'password'     => bcrypt('Password123'),
        'is_active'    => true,
    ]);
    $this->borrowerUser->assignRole(UserRole::Borrower->value);

    $this->borrower = Borrower::create([
        'full_name'      => 'Wanjiku Kamau',
        'id_number'      => '28471928',
        'phone_number'   => '254722000222',
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
        'application_number'   => 'APP-DASH-01',
        'borrower_id'          => $this->borrower->id,
        'loan_product_id'      => $this->product->id,
        'requested_principal'  => '25000.00',
        'requested_tenure_days'=> 30,
        'status'               => 'approved',
    ]);

    $this->loan = Loan::create([
        'loan_number'         => 'LN-DASH-01',
        'loan_application_id' => $this->application->id,
        'borrower_id'         => $this->borrower->id,
        'loan_product_id'     => $this->product->id,
        'principal_disbursed' => '25000.00',
        'annual_interest_rate'=> '18.00',
        'tenure_days'         => 30,
        'status'              => LoanStatus::DISBURSED,
        'disbursed_at'        => CarbonImmutable::now()->subDays(10),
        'due_date'            => CarbonImmutable::now()->addDays(20),
        'is_in_duplum_locked' => false,
    ]);
});

it('renders lender command center with 200 OK for authorized staff', function (): void {
    $this->withoutVite();

    $response = $this->actingAs($this->staffUser)
        ->get('/lender/dashboard');

    $response->assertStatus(200)
        ->assertSee('Institutional Command Center')
        ->assertSee('Active Capital')
        ->assertSee('25,000.00')
        ->assertSee('Maker-Checker Authorization Desk');
});

it('blocks borrower role from accessing lender command center with 403', function (): void {
    $this->withoutVite();

    $response = $this->actingAs($this->borrowerUser)
        ->get('/lender/dashboard');

    $response->assertStatus(403);
});

it('renders in-duplum frozen counts and pending maker-checker queues on dashboard', function (): void {
    $this->withoutVite();

    // 1. Create a frozen loan
    $this->loan->update(['is_in_duplum_locked' => true, 'status' => LoanStatus::DEFAULTED]);
    InDuplumTracking::create([
        'loan_id'                      => $this->loan->id,
        'defaulted_at'                 => CarbonImmutable::now(),
        'default_principal_snapshot'   => '25000.00',
        'total_post_default_interest'  => '25000.00',
        'total_post_default_penalties' => '0.00',
        'statutory_cap_limit'          => '25000.00',
        'is_frozen'                    => true,
        'frozen_at'                    => CarbonImmutable::now(),
    ]);

    // 2. Create pending Maker-Checker request
    MakerCheckerRequest::create([
        'maker_user_id'      => $this->staffUser->id,
        'action_type'        => 'loan_disbursement',
        'target_entity_type' => Loan::class,
        'target_entity_id'   => $this->loan->id,
        'payload'            => ['amount' => '25000.00'],
        'status'             => 'pending',
    ]);

    // 3. Create a recent ledger entry
    LoanLedgerEntry::create([
        'loan_id'                   => $this->loan->id,
        'entry_type'                => 'disbursement',
        'debit'                     => '25000.00',
        'credit'                    => '0.00',
        'running_principal_balance' => '25000.00',
        'running_interest_balance'  => '0.00',
        'running_fees_balance'      => '0.00',
        'total_running_balance'     => '25000.00',
        'reference'                 => 'DISB-DASH-TEST',
    ]);

    $response = $this->actingAs($this->staffUser)
        ->get('/lender/dashboard');
$response->assertStatus(200)
    ->assertSee('loan disbursement')
    ->assertSee('FROZEN')
    ->assertSee('DISB-DASH-TEST');
});