<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Models\AmortizationSchedule;
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

function svwUser(string $n, string $role): User
{
    $user = User::create([
        'name'         => 'Svw User ' . $n,
        'email'        => 'svw' . $n . '@svw.test',
        'password'     => 'Demo@12345',
        'phone_number' => '254744' . str_pad($n, 6, '0', STR_PAD_LEFT),
        'is_active'    => true,
    ]);
    $user->assignRole($role);

    return $user;
}

function svwLoan(string $n, ?User $owner, bool $withSchedule, bool $partial = false): Loan
{
    $borrower = Borrower::create([
        'user_id'        => $owner?->id,
        'full_name'      => 'Svw Borrower ' . $n,
        'id_number'      => '3100' . str_pad($n, 4, '0', STR_PAD_LEFT),
        'phone_number'   => '254755' . str_pad($n, 6, '0', STR_PAD_LEFT),
        'credit_limit'   => '15000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::firstOrCreate(['product_code' => 'SVW-30'], [
        'name'                        => 'Svw Product',
        'min_principal'               => '1000.00',
        'max_principal'               => '50000.00',
        'default_tenure_days'         => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active'                   => true,
    ]);

    $application = LoanApplication::create([
        'application_number'    => 'APP-SVW-' . $n,
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => $product->id,
        'requested_principal'   => '1125.00',
        'requested_tenure_days' => 30,
        'status'                => 'approved',
    ]);

    $loan = Loan::create([
        'loan_number'          => 'LN-SVW-' . $n,
        'loan_application_id'  => $application->id,
        'borrower_id'          => $borrower->id,
        'loan_product_id'      => $product->id,
        'principal_disbursed'  => '1125.00',
        'annual_interest_rate' => '18.00',
        'tenure_days'          => 30,
        'status'               => LoanStatus::DISBURSED,
        'disbursed_at'         => CarbonImmutable::now()->subDays(5),
        'due_date'             => CarbonImmutable::now()->addDays(25),
        'is_in_duplum_locked'  => false,
    ]);

    if ($withSchedule) {
        AmortizationSchedule::create([
            'loan_id'               => $loan->id,
            'installment_number'    => 1,
            'due_date'              => CarbonImmutable::now()->addDays(25)->toDateString(),
            'principal_due'         => '1125.00',
            'interest_due'          => '16.88',
            'fees_due'              => '56.25',
            'total_installment_due' => '1198.13',
            'principal_paid'        => $partial ? '141.88' : '0.00',
            'interest_paid'         => '0.00',
            'fees_paid'             => $partial ? '56.25' : '0.00',
            'status'                => $partial ? 'partial' : 'pending',
        ]);
    }

    return $loan;
}

it('shows the borrower their repayment schedule on the account page', function (): void {
    $user = svwUser('1', 'borrower');
    svwLoan('1', $user, true);

    $this->actingAs($user)
        ->get('/borrower/account')
        ->assertOk()
        ->assertSee('Your repayment schedule')
        ->assertSee('Payment 1')
        ->assertSee('KES 1,198.13');
});

it('shows only what is still to pay after a part payment', function (): void {
    $user = svwUser('2', 'borrower');
    svwLoan('2', $user, true, partial: true);

    $this->actingAs($user)
        ->get('/borrower/account')
        ->assertOk()
        ->assertSee('KES 1,000.00')
        ->assertDontSee('KES 1,198.13');
});

it('shows no schedule card for a loan that has no schedule', function (): void {
    $user = svwUser('3', 'borrower');
    svwLoan('3', $user, false);

    $this->actingAs($user)
        ->get('/borrower/account')
        ->assertOk()
        ->assertSee('LN-SVW-3')
        ->assertDontSee('Your repayment schedule');
});

it('shows staff what has been paid on each instalment and the new empty wording', function (): void {
    $loan = svwLoan('4', null, true, partial: true);
    $empty = svwLoan('5', null, false);
    $admin = svwUser('6', 'lender_admin');

    $this->actingAs($admin)
        ->get(route('lender.loans.show', [$loan, 'tab' => 'schedule']))
        ->assertOk()
        ->assertSee('KES 198.13')
        ->assertSee('Partial');

    $this->actingAs($admin)
        ->get(route('lender.loans.show', [$empty, 'tab' => 'schedule']))
        ->assertOk()
        ->assertSee('No schedule yet')
        ->assertDontSee('later phase');
});