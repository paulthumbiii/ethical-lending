<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(RolesSeeder::class);
});

function bacUser(string $n, string $role = 'borrower'): User
{
    $user = User::create([
        'name'         => 'Bac User ' . $n,
        'email'        => 'bac' . $n . '@bac.test',
        'password'     => 'Demo@12345',
        'phone_number' => '254733' . str_pad($n, 6, '0', STR_PAD_LEFT),
        'is_active'    => true,
    ]);
    $user->assignRole($role);

    return $user;
}

function bacBorrower(string $n, ?User $user): Borrower
{
    return Borrower::create([
        'user_id'        => $user?->id,
        'full_name'      => 'Bac Borrower ' . $n,
        'id_number'      => '2847' . str_pad($n, 4, '0', STR_PAD_LEFT),
        'phone_number'   => '254722' . str_pad($n, 6, '0', STR_PAD_LEFT),
        'credit_limit'   => '15000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);
}

function bacLoan(Borrower $borrower, string $number, LoanStatus $status): Loan
{
    $product = LoanProduct::firstOrCreate(['product_code' => 'BAC-30'], [
        'name'                        => 'Bac Product',
        'min_principal'               => '1000.00',
        'max_principal'               => '50000.00',
        'default_tenure_days'         => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active'                   => true,
    ]);

    $application = LoanApplication::create([
        'application_number'    => 'APP-' . $number,
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => $product->id,
        'requested_principal'   => '5000.00',
        'requested_tenure_days' => 30,
        'status'                => 'approved',
    ]);

    return Loan::create([
        'loan_number'          => $number,
        'loan_application_id'  => $application->id,
        'borrower_id'          => $borrower->id,
        'loan_product_id'      => $product->id,
        'principal_disbursed'  => '5000.00',
        'annual_interest_rate' => '18.00',
        'tenure_days'          => 30,
        'status'               => $status,
        'disbursed_at'         => CarbonImmutable::now()->subDays(5),
        'due_date'             => CarbonImmutable::now()->addDays(25),
        'is_in_duplum_locked'  => false,
    ]);
}

it('sends a guest to login', function (): void {
    $this->get('/borrower/account')->assertRedirect('/login');
});

it('keeps staff out of the borrower account page', function (): void {
    $this->actingAs(bacUser('1', 'finance_officer'))
        ->get('/borrower/account')
        ->assertForbidden();
});

it('shows the borrower their details with the ID masked, and their loans', function (): void {
    $user = bacUser('2');
    $borrower = bacBorrower('2', $user);
    bacLoan($borrower, 'LN-BAC-001', LoanStatus::DISBURSED);
    bacLoan($borrower, 'LN-BAC-002', LoanStatus::SETTLED);

    $this->actingAs($user)
        ->get('/borrower/account')
        ->assertOk()
        ->assertSee('Bac Borrower 2')
        ->assertSee('254722000002')
        ->assertSee('****0002')
        ->assertDontSee('28470002')
        ->assertSee('KES 15,000.00')
        ->assertSee('LN-BAC-001')
        ->assertSee('LN-BAC-002')
        ->assertSee('KES 5,000.00');
});

it('never shows another borrower\'s loans', function (): void {
    $user = bacUser('3');
    bacBorrower('3', $user);
    bacLoan(bacBorrower('4', null), 'LN-BAC-OTHER', LoanStatus::DISBURSED);

    $this->actingAs($user)
        ->get('/borrower/account')
        ->assertOk()
        ->assertDontSee('LN-BAC-OTHER')
        ->assertSee('You have no loans yet.');
});

it('explains when the login has no borrower profile', function (): void {
    $this->actingAs(bacUser('5'))
        ->get('/borrower/account')
        ->assertOk()
        ->assertSee('not linked to this login yet');
});