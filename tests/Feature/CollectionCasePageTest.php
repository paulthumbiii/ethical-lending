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
use App\Models\PromiseToPay;
use App\Models\User;
use App\Services\Financial\LoanLedgerService;
use App\Services\Servicing\PromiseToPayService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00', 'Africa/Nairobi'));
});

function cpgUser(UserRole $role, string $phone): User
{
    Role::findOrCreate($role->value, 'web');

    $user = User::create([
        'name'         => 'Cpg ' . $role->value,
        'email'        => 'cpg.' . $phone . '@ethical-lending.test',
        'password'     => bcrypt('Demo@12345'),
        'phone_number' => $phone,
        'is_active'    => true,
    ]);
    $user->assignRole($role->value);

    return $user;
}

function cpgCase(): CollectionCase
{
    $borrower = Borrower::create([
        'full_name'      => 'Cpg Borrower',
        'id_number'      => '70000001',
        'phone_number'   => '254755000001',
        'credit_limit'   => '15000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::create([
        'name'                        => 'Cpg Test Product',
        'product_code'                => 'CPG-30',
        'min_principal'               => '1000.00',
        'max_principal'               => '50000.00',
        'default_tenure_days'         => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active'                   => true,
    ]);

    $application = LoanApplication::create([
        'application_number'    => 'APP-CPG-01',
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => $product->id,
        'requested_principal'   => '10000.00',
        'requested_tenure_days' => 30,
        'status'                => 'approved',
    ]);

    $loan = Loan::create([
        'loan_number'          => 'LN-CPG-01',
        'loan_application_id'  => $application->id,
        'borrower_id'          => $borrower->id,
        'loan_product_id'      => $product->id,
        'principal_disbursed'  => '10000.00',
        'annual_interest_rate' => '18.00',
        'tenure_days'          => 30,
        'status'               => LoanStatus::DISBURSED,
        'disbursed_at'         => CarbonImmutable::now()->subDays(40),
        'due_date'             => CarbonImmutable::now()->subDays(10),
        'is_in_duplum_locked'  => false,
    ]);

    app(LoanLedgerService::class)->recordEntry($loan->id, 'DISBURSEMENT_PRINCIPAL', '10000.00', '0.00', 'CPG-1', 'test');

    return CollectionCase::create([
        'loan_id'     => $loan->id,
        'borrower_id' => $borrower->id,
        'dpd_bucket'  => 'DPD_1_30',
        'case_status' => 'open',
    ]);
}

it('sends guests to the login page', function (): void {
    $case = cpgCase();

    $this->get(route('lender.collections.show', $case))->assertRedirect(route('login'));
});

it('keeps borrowers out of the case page', function (): void {
    $case = cpgCase();
    $borrower = cpgUser(UserRole::Borrower, '254700000401');

    $this->actingAs($borrower)->get(route('lender.collections.show', $case))->assertForbidden();
});

it('shows the loan, borrower and days past due to a collections officer', function (): void {
    $case = cpgCase();
    $officer = cpgUser(UserRole::CollectionsOfficer, '254700000402');

    $this->actingAs($officer)
        ->get(route('lender.collections.show', $case))
        ->assertOk()
        ->assertSee('Cpg Borrower')
        ->assertSee('LN-CPG-01')
        ->assertSee('10 days')
        ->assertSee('Record promise');
});

it('lets a finance officer read the page but not record a promise', function (): void {
    $case = cpgCase();
    $finance = cpgUser(UserRole::FinanceOfficer, '254700000403');

    $this->actingAs($finance)
        ->get(route('lender.collections.show', $case))
        ->assertOk()
        ->assertSee('LN-CPG-01')
        ->assertDontSee('Record promise');

    $this->actingAs($finance)
        ->post(route('lender.collections.promises.store', $case), ['amount' => '2500', 'promised_date' => '2026-10-29'])
        ->assertForbidden();

    expect(PromiseToPay::count())->toBe(0);
});

it('records a promise from the form and shows it in the history', function (): void {
    $case = cpgCase();
    $officer = cpgUser(UserRole::CollectionsOfficer, '254700000404');

    $this->actingAs($officer)
        ->post(route('lender.collections.promises.store', $case), ['amount' => '2500', 'promised_date' => '2026-10-29'])
        ->assertRedirect(route('lender.collections.show', $case))
        ->assertSessionHas('status', 'Promise to pay recorded.');

    expect(PromiseToPay::count())->toBe(1);

    $this->actingAs($officer)
        ->get(route('lender.collections.show', $case))
        ->assertOk()
        ->assertSee('KES 2,500.00')
        ->assertSee('29 Oct 2026');
});

it('shows the service message when the date is in the past', function (): void {
    $case = cpgCase();
    $officer = cpgUser(UserRole::CollectionsOfficer, '254700000405');

    $this->actingAs($officer)
        ->post(route('lender.collections.promises.store', $case), ['amount' => '2500', 'promised_date' => '2026-10-20'])
        ->assertRedirect(route('lender.collections.show', $case))
        ->assertSessionHas('error', 'The promised date cannot be in the past.');

    expect(PromiseToPay::count())->toBe(0);
});

it('rejects an amount that is not a number', function (): void {
    $case = cpgCase();
    $officer = cpgUser(UserRole::CollectionsOfficer, '254700000406');

    $this->actingAs($officer)
        ->post(route('lender.collections.promises.store', $case), ['amount' => 'abc', 'promised_date' => '2026-10-29'])
        ->assertSessionHasErrors('amount');

    expect(PromiseToPay::count())->toBe(0);
});

it('hides the form while a promise is live', function (): void {
    $case = cpgCase();
    $officer = cpgUser(UserRole::CollectionsOfficer, '254700000407');
    app(PromiseToPayService::class)->record($case, '2500', '2026-10-29');

    $this->actingAs($officer)
        ->get(route('lender.collections.show', $case))
        ->assertOk()
        ->assertSee('already live')
        ->assertDontSee('Record promise');
});