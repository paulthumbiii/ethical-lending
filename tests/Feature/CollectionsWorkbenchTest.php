<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Enums\UserRole;
use App\Models\Borrower;
use App\Models\CollectionAction;
use App\Models\CollectionCase;
use App\Models\InDuplumTracking;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\OutboxEvent;
use App\Models\PromiseToPay;
use App\Models\RegulatoryCooldownLock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function cwbUser(UserRole $role, string $phone): User
{
    Role::findOrCreate($role->value, 'web');

    $user = User::create([
        'name'         => 'Test ' . $role->value,
        'email'        => $role->value . '.' . $phone . '@ethical-lending.test',
        'password'     => bcrypt('Demo@12345'),
        'phone_number' => $phone,
        'is_active'    => true,
    ]);

    $user->assignRole($role->value);

    return $user;
}

function cwbCase(string $name, string $bucket, string $n): CollectionCase
{
    $borrower = Borrower::create([
        'full_name'      => $name,
        'id_number'      => '3000' . $n,
        'phone_number'   => '254711000' . $n,
        'credit_limit'   => '15000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::firstOrCreate(
        ['product_code' => 'CWB-30'],
        [
            'name'                        => 'Workbench Test Product',
            'min_principal'               => '1000.00',
            'max_principal'               => '50000.00',
            'default_tenure_days'         => 30,
            'interest_calculation_method' => 'reducing_balance',
            'is_active'                   => true,
        ]
    );

    $application = LoanApplication::create([
        'application_number'    => 'APP-CWB-' . $n,
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => $product->id,
        'requested_principal'   => '12000.00',
        'requested_tenure_days' => 30,
        'status'                => 'approved',
    ]);

    $loan = Loan::create([
        'loan_number'          => 'LN-CWB-' . $n,
        'loan_application_id'  => $application->id,
        'borrower_id'          => $borrower->id,
        'loan_product_id'      => $product->id,
        'principal_disbursed'  => '12000.00',
        'annual_interest_rate' => '18.00',
        'tenure_days'          => 30,
        'status'               => LoanStatus::DEFAULTED,
        'disbursed_at'         => CarbonImmutable::now()->subDays(70),
        'due_date'             => CarbonImmutable::now()->subDays(40),
        'defaulted_at'         => CarbonImmutable::now()->subDays(10),
        'is_in_duplum_locked'  => false,
    ]);

    return CollectionCase::create([
        'loan_id'                => $loan->id,
        'borrower_id'            => $borrower->id,
        'dpd_bucket'             => $bucket,
        'assigned_agent_user_id' => null,
        'case_status'            => 'open',
        'last_contacted_at'      => null,
    ]);
}

function cwbLock(CollectionCase $case): RegulatoryCooldownLock
{
    return RegulatoryCooldownLock::create([
        'borrower_id'  => $case->borrower_id,
        'lock_type'    => 'COLLECTIONS_SMS',
        'locked_until' => CarbonImmutable::now()->addHours(20),
        'reason'       => '48-hour quiet period workbench test',
    ]);
}

function cwbPromise(CollectionCase $case): PromiseToPay
{
    return PromiseToPay::create([
        'collection_case_id' => $case->id,
        'promised_date'      => CarbonImmutable::now()->addDays(3)->toDateString(),
        'promised_amount'    => '2500.00',
        'status'             => 'pending',
    ]);
}

it('sends guests to the login page', function () {
    $this->get(route('lender.collections.index'))->assertRedirect(route('login'));
});

it('keeps borrowers out of the collections workbench', function () {
    $borrower = cwbUser(UserRole::Borrower, '254700000201');

    $this->actingAs($borrower)->get(route('lender.collections.index'))->assertForbidden();
});

it('keeps credit officers out of the collections workbench', function () {
    $credit = cwbUser(UserRole::CreditOfficer, '254700000202');

    $this->actingAs($credit)->get(route('lender.collections.index'))->assertForbidden();
});

it('lets collections viewer roles open the workbench', function (string $role) {
    $this->withoutVite();
    $user = cwbUser(UserRole::from($role), '254700000203');

    $this->actingAs($user)->get(route('lender.collections.index'))->assertOk();
})->with(['lender_admin', 'collections_officer', 'finance_officer']);

it('shows quiet period, promise to pay and the in-duplum cap for a case', function () {
    $this->withoutVite();
    $viewer = cwbUser(UserRole::CollectionsOfficer, '254700000204');
    $case = cwbCase('Amani Otieno', 'DPD_61_90', '001');
    cwbLock($case);
    cwbPromise($case);

    InDuplumTracking::create([
        'loan_id'                      => $case->loan_id,
        'defaulted_at'                 => CarbonImmutable::now()->subDays(10),
        'default_principal_snapshot'   => '12000.00',
        'total_post_default_interest'  => '3200.00',
        'total_post_default_penalties' => '1500.00',
        'statutory_cap_limit'          => '12000.00',
        'is_frozen'                    => false,
        'frozen_at'                    => null,
    ]);

    $this->actingAs($viewer)
        ->get(route('lender.collections.index'))
        ->assertOk()
        ->assertSee('Amani Otieno')
        ->assertSee('LN-CWB-001')
        ->assertSee('about 20 h left')
        ->assertSee('KES 2,500.00')
        ->assertSee('KES 4,700.00')
        ->assertSee('KES 12,000.00');
});

it('filters cases by days-past-due bucket', function () {
    $this->withoutVite();
    $viewer = cwbUser(UserRole::FinanceOfficer, '254700000205');
    cwbCase('Amani Otieno', 'DPD_1_30', '001');
    cwbCase('Baraka Mwangi', 'DPD_90_PLUS', '002');

    $this->actingAs($viewer)
        ->get(route('lender.collections.index', ['bucket' => 'DPD_90_PLUS']))
        ->assertOk()
        ->assertSee('Baraka Mwangi')
        ->assertDontSee('Amani Otieno');
});

it('filters cases by quiet period', function () {
    $this->withoutVite();
    $viewer = cwbUser(UserRole::FinanceOfficer, '254700000206');
    $locked = cwbCase('Amani Otieno', 'DPD_31_60', '001');
    cwbCase('Baraka Mwangi', 'DPD_31_60', '002');
    cwbLock($locked);

    $this->actingAs($viewer)
        ->get(route('lender.collections.index', ['cooldown' => 'active']))
        ->assertSee('Amani Otieno')
        ->assertDontSee('Baraka Mwangi');

    $this->actingAs($viewer)
        ->get(route('lender.collections.index', ['cooldown' => 'none']))
        ->assertSee('Baraka Mwangi')
        ->assertDontSee('Amani Otieno');
});

it('filters cases by promise to pay', function () {
    $this->withoutVite();
    $viewer = cwbUser(UserRole::FinanceOfficer, '254700000207');
    $promised = cwbCase('Amani Otieno', 'DPD_31_60', '001');
    cwbCase('Baraka Mwangi', 'DPD_31_60', '002');
    cwbPromise($promised);

    $this->actingAs($viewer)
        ->get(route('lender.collections.index', ['ptp' => 'active']))
        ->assertSee('Amani Otieno')
        ->assertDontSee('Baraka Mwangi');

    $this->actingAs($viewer)
        ->get(route('lender.collections.index', ['ptp' => 'none']))
        ->assertSee('Baraka Mwangi')
        ->assertDontSee('Amani Otieno');
});

it('rejects an unknown bucket filter', function () {
    $viewer = cwbUser(UserRole::FinanceOfficer, '254700000208');

    $this->actingAs($viewer)
        ->get(route('lender.collections.index', ['bucket' => 'NOT_A_BUCKET']))
        ->assertSessionHasErrors('bucket');
});

it('is read-only: viewing sends nothing and no write route exists', function () {
    $this->withoutVite();
    $viewer = cwbUser(UserRole::CollectionsOfficer, '254700000209');
    cwbCase('Amani Otieno', 'DPD_31_60', '001');

    $this->actingAs($viewer)->get(route('lender.collections.index'))->assertOk();

    expect(CollectionAction::count())->toBe(0)
        ->and(OutboxEvent::count())->toBe(0);

    $this->actingAs($viewer)->post('/lender/collections')->assertStatus(405);
});

it('says the contact window is open at 10:00 EAT', function () {
    $this->withoutVite();
    $viewer = cwbUser(UserRole::CollectionsOfficer, '254700000210');

    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00:00', 'Africa/Nairobi'));

    $this->actingAs($viewer)
        ->get(route('lender.collections.index'))
        ->assertOk()
        ->assertSee('Contact window open')
        ->assertSee('It is 10:00 in Nairobi');
});

it('warns that it is outside legal hours at 21:00 EAT', function () {
    $this->withoutVite();
    $viewer = cwbUser(UserRole::CollectionsOfficer, '254700000211');

    $this->travelTo(CarbonImmutable::parse('2026-10-19 21:00:00', 'Africa/Nairobi'));

    $this->actingAs($viewer)
        ->get(route('lender.collections.index'))
        ->assertOk()
        ->assertSee('Outside legal hours')
        ->assertSee('It is 21:00 in Nairobi');
});