<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Enums\UserRole;
use App\Models\AuditTrail;
use App\Models\Borrower;
use App\Models\CollectionCase;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\PromiseToPay;
use App\Models\User;
use App\Services\Financial\LoanLedgerService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * A borrower with a login and a KES 10,000 loan, due 1 Oct 2026 (25 days overdue on 26 Oct).
 *
 * @return array{0: User, 1: Borrower, 2: Loan}
 */
function bspParty(string $n): array
{
    $user = User::create([
        'name'         => 'Bsp User ' . $n,
        'email'        => "bsp{$n}@example.test",
        'password'     => 'secret-pass-1',
        'phone_number' => '254766079' . str_pad($n, 3, '0', STR_PAD_LEFT),
        'is_active'    => true,
    ]);
    $user->assignRole(UserRole::Borrower->value);

    $borrower = Borrower::create([
        'user_id'        => $user->id,
        'full_name'      => 'Bsp Borrower ' . $n,
        'id_number'      => '55007' . str_pad($n, 3, '0', STR_PAD_LEFT),
        'phone_number'   => '254766078' . str_pad($n, 3, '0', STR_PAD_LEFT),
        'credit_limit'   => '50000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::firstOrCreate(['product_code' => 'BSP-30'], [
        'name'                        => 'Bsp Product',
        'min_principal'               => '1000.00',
        'max_principal'               => '50000.00',
        'default_tenure_days'         => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active'                   => true,
    ]);

    $application = LoanApplication::create([
        'application_number'    => 'APP-BSP-' . $n,
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => $product->id,
        'requested_principal'   => '10000.00',
        'requested_tenure_days' => 30,
        'status'                => 'approved',
    ]);

    $start = CarbonImmutable::parse('2026-09-01 10:00:00', 'Africa/Nairobi');

    $loan = Loan::create([
        'loan_number'          => 'LN-BSP-' . $n,
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

    app(LoanLedgerService::class)->recordEntry($loan->id, 'DISBURSEMENT_PRINCIPAL', '10000.00', '0.00', 'BSP-DISB-' . $n, 'test');

    return [$user, $borrower, $loan];
}

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00', 'Africa/Nairobi'));
    $this->seed(RolesSeeder::class);

    $party = bspParty('1');
    $this->user = $party[0];
    $this->borrower = $party[1];
    $this->loan = $party[2];
});

it('shows the promise card when the loan is overdue and has no promise', function (): void {
    $this->actingAs($this->user)->get(route('borrower.dashboard'))
        ->assertOk()
        ->assertSee('Tell us when you will pay')
        ->assertSee('Save my promise');
});

it('hides the promise card when the loan is not overdue', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 12:00:00', 'Africa/Nairobi'));

    $this->actingAs($this->user)->get(route('borrower.dashboard'))
        ->assertOk()
        ->assertDontSee('Tell us when you will pay');
});

it('records a promise, opens the case and writes the audit row', function (): void {
    $this->actingAs($this->user)
        ->post(route('borrower.promise.store', $this->loan), ['amount' => '2500.00', 'promised_date' => '2026-10-29'])
        ->assertRedirect(route('borrower.dashboard'))
        ->assertSessionHas('status');

    $case = CollectionCase::query()->where('loan_id', $this->loan->id)->firstOrFail();
    $promise = PromiseToPay::query()->where('collection_case_id', $case->id)->firstOrFail();

    expect((string) $promise->promised_amount)->toBe('2500.00')
        ->and($promise->promised_date->toDateString())->toBe('2026-10-29')
        ->and($promise->status)->toBe('pending')
        ->and(AuditTrail::query()->where('action', 'PROMISE_TO_PAY_RECORDED')->where('user_id', $this->user->id)->count())->toBe(1);
});

it('shows a confirmation instead of the form once a promise is live', function (): void {
    $this->actingAs($this->user)
        ->post(route('borrower.promise.store', $this->loan), ['amount' => '2500.00', 'promised_date' => '2026-10-29']);

    // The first page load uses up the one-time thank-you banner.
    $this->actingAs($this->user)->get(route('borrower.dashboard'));

    $this->actingAs($this->user)->get(route('borrower.dashboard'))
        ->assertOk()
        ->assertSee('You promised KES 2,500.00 by 29 Oct 2026')
        ->assertDontSee('Save my promise');
});

it('refuses a promise bigger than the borrower owes', function (): void {
    $this->actingAs($this->user)
        ->post(route('borrower.promise.store', $this->loan), ['amount' => '10000.01', 'promised_date' => '2026-10-29'])
        ->assertSessionHasErrors('promise');

    expect(PromiseToPay::query()->count())->toBe(0);
});

it('refuses a promise when the loan is not overdue', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 12:00:00', 'Africa/Nairobi'));

    $this->actingAs($this->user)
        ->post(route('borrower.promise.store', $this->loan), ['amount' => '2500.00', 'promised_date' => '2026-09-20'])
        ->assertSessionHasErrors('promise');

    expect(PromiseToPay::query()->count())->toBe(0)
        ->and(CollectionCase::query()->count())->toBe(0);
});

it('refuses a second promise while one is live', function (): void {
    $this->actingAs($this->user)
        ->post(route('borrower.promise.store', $this->loan), ['amount' => '2500.00', 'promised_date' => '2026-10-29']);

    $this->actingAs($this->user)
        ->post(route('borrower.promise.store', $this->loan), ['amount' => '1000.00', 'promised_date' => '2026-10-30'])
        ->assertSessionHasErrors('promise');

    expect(PromiseToPay::query()->count())->toBe(1);
});

it('returns 404 when a borrower promises on someone else\'s loan', function (): void {
    $other = bspParty('2');

    $this->actingAs($this->user)
        ->post(route('borrower.promise.store', $other[2]), ['amount' => '2500.00', 'promised_date' => '2026-10-29'])
        ->assertNotFound();

    expect(PromiseToPay::query()->count())->toBe(0);
});