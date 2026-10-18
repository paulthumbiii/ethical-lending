<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Models\AuditTrail;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function bapBorrower(): array
{
    Role::findOrCreate('borrower', 'web');

    $user = User::create([
        'name' => 'Apply Tester',
        'email' => 'bap@example.com',
        'password' => 'Salama2026',
        'phone_number' => '254755000001',
        'is_active' => true,
    ]);
    $user->assignRole('borrower');

    $borrower = Borrower::create([
        'user_id' => $user->id,
        'full_name' => 'Apply Tester',
        'id_number' => '33000001',
        'phone_number' => '254755000001',
        'credit_limit' => '0.00',
        'risk_band' => RiskBand::C,
        'is_blacklisted' => false,
    ]);

    return [$user, $borrower];
}

function bapProduct(bool $active = true): LoanProduct
{
    return LoanProduct::create([
        'name' => 'Apply Test Product',
        'product_code' => 'BAP-30',
        'min_principal' => '1000.00',
        'max_principal' => '20000.00',
        'default_tenure_days' => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active' => $active,
    ]);
}

it('sends guests to the login page', function () {
    $this->get(route('borrower.apply.create'))->assertRedirect(route('login'));
});

it('keeps staff out of the apply page', function () {
    Role::findOrCreate('lender_admin', 'web');
    $staff = User::create([
        'name' => 'Staff', 'email' => 'bap.staff@example.com', 'password' => 'Salama2026',
        'phone_number' => '254755000002', 'is_active' => true,
    ]);
    $staff->assignRole('lender_admin');

    $this->actingAs($staff)->get(route('borrower.apply.create'))->assertForbidden();
});

it('shows the product with its limits to a borrower', function () {
    $this->withoutVite();
    [$user] = bapBorrower();
    bapProduct();

    $this->actingAs($user)->get(route('borrower.apply.create'))
        ->assertOk()
        ->assertSee('Apply Test Product')
        ->assertSee('KES 1,000')
        ->assertSee('KES 20,000');
});

it('saves a pending application and writes an audit row', function () {
    $this->withoutVite();
    [$user, $borrower] = bapBorrower();
    $product = bapProduct();

    $this->actingAs($user)->post(route('borrower.apply.store'), [
        'loan_product_id' => $product->id,
        'amount' => '5000',
    ])->assertRedirect(route('borrower.apply.create'));

    $app = LoanApplication::first();

    expect($app)->not->toBeNull();
    expect($app->borrower_id)->toBe($borrower->id);
    expect($app->status)->toBe('pending');
    expect($app->requested_tenure_days)->toBe(30);
    expect(bccomp((string) $app->requested_principal, '5000.00', 2))->toBe(0);
    expect(AuditTrail::where('action', 'LOAN_APPLICATION_SUBMITTED')->count())->toBe(1);
});

it('refuses an amount below the product minimum', function () {
    $this->withoutVite();
    [$user] = bapBorrower();
    $product = bapProduct();

    $this->actingAs($user)->post(route('borrower.apply.store'), [
        'loan_product_id' => $product->id,
        'amount' => '500',
    ])->assertSessionHasErrors('amount');

    expect(LoanApplication::count())->toBe(0);
});

it('refuses an amount above the product maximum', function () {
    $this->withoutVite();
    [$user] = bapBorrower();
    $product = bapProduct();

    $this->actingAs($user)->post(route('borrower.apply.store'), [
        'loan_product_id' => $product->id,
        'amount' => '20001',
    ])->assertSessionHasErrors('amount');

    expect(LoanApplication::count())->toBe(0);
});

it('refuses decimals and text in the amount', function () {
    $this->withoutVite();
    [$user] = bapBorrower();
    $product = bapProduct();

    foreach (['5000.50', 'abc', '-100'] as $bad) {
        $this->actingAs($user)->post(route('borrower.apply.store'), [
            'loan_product_id' => $product->id,
            'amount' => $bad,
        ])->assertSessionHasErrors('amount');
    }

    expect(LoanApplication::count())->toBe(0);
});

it('blocks a second application while one is in progress', function () {
    $this->withoutVite();
    [$user] = bapBorrower();
    $product = bapProduct();

    $payload = ['loan_product_id' => $product->id, 'amount' => '5000'];

    $this->actingAs($user)->post(route('borrower.apply.store'), $payload);
    $this->actingAs($user)->post(route('borrower.apply.store'), $payload)->assertSessionHasErrors('amount');

    expect(LoanApplication::count())->toBe(1);
});

it('blocks a new application while a loan is still active', function () {
    $this->withoutVite();
    [$user, $borrower] = bapBorrower();
    $product = bapProduct();

    $old = LoanApplication::create([
        'application_number' => 'APP-BAP-OLD',
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'requested_principal' => '5000.00',
        'requested_tenure_days' => 30,
        'status' => 'approved',
    ]);

    Loan::create([
        'loan_number' => 'LN-BAP-01',
        'loan_application_id' => $old->id,
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'principal_disbursed' => '5000.00',
        'annual_interest_rate' => '18.00',
        'tenure_days' => 30,
        'status' => LoanStatus::DISBURSED,
        'disbursed_at' => CarbonImmutable::now()->subDays(5),
        'due_date' => CarbonImmutable::now()->addDays(25),
        'is_in_duplum_locked' => false,
    ]);

    $this->actingAs($user)->post(route('borrower.apply.store'), [
        'loan_product_id' => $product->id,
        'amount' => '3000',
    ])->assertSessionHasErrors('amount');

    expect(LoanApplication::count())->toBe(1);
});

it('refuses an inactive product', function () {
    $this->withoutVite();
    [$user] = bapBorrower();
    $product = bapProduct(active: false);

    $this->actingAs($user)->post(route('borrower.apply.store'), [
        'loan_product_id' => $product->id,
        'amount' => '5000',
    ])->assertSessionHasErrors('loan_product_id');

    expect(LoanApplication::count())->toBe(0);
});