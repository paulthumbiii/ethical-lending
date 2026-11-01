<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Enums\UserRole;
use App\Models\Borrower;
use App\Models\CollectionCase;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\Servicing\PromiseToPayService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function rbbUser(string $n, UserRole $role): User
{
    $user = User::create([
        'name'         => 'Rbb ' . $n,
        'email'        => "rbb{$n}@example.test",
        'password'     => 'secret-pass-1',
        'phone_number' => '254766055' . str_pad($n, 3, '0', STR_PAD_LEFT),
        'is_active'    => true,
    ]);
    $user->assignRole($role->value);

    return $user;
}

function rbbCase(): CollectionCase
{
    $borrower = Borrower::create([
        'full_name'      => 'Rbb Borrower',
        'id_number'      => '55005501',
        'phone_number'   => '254766056001',
        'credit_limit'   => '50000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::create([
        'product_code'                => 'RBB-30',
        'name'                        => 'Rbb Product',
        'min_principal'               => '1000.00',
        'max_principal'               => '50000.00',
        'default_tenure_days'         => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active'                   => true,
    ]);

    $application = LoanApplication::create([
        'application_number'    => 'APP-RBB-1',
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => $product->id,
        'requested_principal'   => '10000.00',
        'requested_tenure_days' => 30,
        'status'                => 'approved',
    ]);

    $start = CarbonImmutable::parse('2026-09-01 10:00:00', 'Africa/Nairobi');

    $loan = Loan::create([
        'loan_number'          => 'LN-RBB-1',
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

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00', 'Africa/Nairobi'));
    $this->seed(RolesSeeder::class);
});

it('shows that the borrower recorded the promise', function (): void {
    $case = rbbCase();
    $staff = rbbUser('1', UserRole::CollectionsOfficer);
    $borrowerUser = rbbUser('2', UserRole::Borrower);

    app(PromiseToPayService::class)->record($case, '2500.00', '2026-10-29', $borrowerUser->id);

    $this->actingAs($staff)->get(route('lender.collections.show', $case))
        ->assertOk()
        ->assertSee('Recorded by')
        ->assertSee('Borrower (self-service)');
});

it('shows the staff member who recorded the promise', function (): void {
    $case = rbbCase();
    $staff = rbbUser('3', UserRole::CollectionsOfficer);

    app(PromiseToPayService::class)->record($case, '2500.00', '2026-10-29', $staff->id);

    $this->actingAs($staff)->get(route('lender.collections.show', $case))
        ->assertOk()
        ->assertSee('Rbb 3')
        ->assertDontSee('Borrower (self-service)');
});