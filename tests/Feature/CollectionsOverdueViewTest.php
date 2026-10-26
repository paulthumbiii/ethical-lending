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
use App\Services\Financial\LoanLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00', 'Africa/Nairobi'));
});

function cdpViewer(): User
{
    Role::findOrCreate(UserRole::CollectionsOfficer->value, 'web');

    $user = User::create([
        'name'         => 'Cdp Viewer',
        'email'        => 'cdp.viewer@ethical-lending.test',
        'password'     => bcrypt('Demo@12345'),
        'phone_number' => '254700000301',
        'is_active'    => true,
    ]);
    $user->assignRole(UserRole::CollectionsOfficer->value);

    return $user;
}

function cdpCase(string $name, string $n, int $dueDaysAgo, bool $ledger, ?int $defaultedDaysAgo = null): CollectionCase
{
    $borrower = Borrower::create([
        'full_name'      => $name,
        'id_number'      => '5000' . $n,
        'phone_number'   => '254733000' . $n,
        'credit_limit'   => '15000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::firstOrCreate(
        ['product_code' => 'CDP-30'],
        [
            'name'                        => 'Cdp Test Product',
            'min_principal'               => '1000.00',
            'max_principal'               => '50000.00',
            'default_tenure_days'         => 30,
            'interest_calculation_method' => 'reducing_balance',
            'is_active'                   => true,
        ]
    );

    $application = LoanApplication::create([
        'application_number'    => 'APP-CDP-' . $n,
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => $product->id,
        'requested_principal'   => '10000.00',
        'requested_tenure_days' => 30,
        'status'                => 'approved',
    ]);

    $loan = Loan::create([
        'loan_number'          => 'LN-CDP-' . $n,
        'loan_application_id'  => $application->id,
        'borrower_id'          => $borrower->id,
        'loan_product_id'      => $product->id,
        'principal_disbursed'  => '10000.00',
        'annual_interest_rate' => '18.00',
        'tenure_days'          => 30,
        'status'               => $defaultedDaysAgo === null ? LoanStatus::DISBURSED : LoanStatus::DEFAULTED,
        'disbursed_at'         => CarbonImmutable::now()->subDays($dueDaysAgo + 30),
        'due_date'             => CarbonImmutable::now()->subDays($dueDaysAgo),
        'defaulted_at'         => $defaultedDaysAgo === null ? null : CarbonImmutable::now()->subDays($defaultedDaysAgo),
        'is_in_duplum_locked'  => false,
    ]);

    if ($ledger) {
        app(LoanLedgerService::class)->recordEntry($loan->id, 'DISBURSEMENT_PRINCIPAL', '10000.00', '0.00', 'CDP-' . $n, 'test');
    }

    return CollectionCase::create([
        'loan_id'     => $loan->id,
        'borrower_id' => $borrower->id,
        'dpd_bucket'  => 'DPD_1_30',
        'case_status' => 'open',
    ]);
}

it('shows exact days past due and the overdue-since date', function (): void {
    cdpCase('Sila Overdue', '001', 10, true);

    $this->actingAs(cdpViewer())
        ->get(route('lender.collections.index'))
        ->assertOk()
        ->assertSee('Sila Overdue')
        ->assertSee('10 days')
        ->assertSee('Overdue since 16 Oct 2026');
});

it('says not overdue when the loan has no balance owed', function (): void {
    cdpCase('Paid Up Person', '002', 10, false);

    $this->actingAs(cdpViewer())
        ->get(route('lender.collections.index'))
        ->assertOk()
        ->assertSee('Paid Up Person')
        ->assertSee('Not overdue')
        ->assertDontSee('Overdue since');
});

it('counts only loans that went to default today', function (): void {
    cdpCase('Today Default', '003', 95, true, 0);
    cdpCase('Old Default', '004', 120, true, 10);

    $this->actingAs(cdpViewer())
        ->get(route('lender.collections.index'))
        ->assertOk()
        ->assertSee('Defaulted today')
        ->assertViewHas('stats', fn (array $stats): bool => $stats['defaulted_today'] === 1);
});