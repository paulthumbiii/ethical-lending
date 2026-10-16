<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Exceptions\Fintech\InDuplumCapExceededException;
use App\Models\Borrower;
use App\Models\InDuplumTracking;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\OutboxEvent;
use App\Services\Financial\InDuplumEnforcementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Explicitly boot Laravel's TestCase alongside RefreshDatabase
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->borrower = Borrower::create([
        'full_name'      => 'Wanjiku Kamau',
        'id_number'      => '28471928',
        'phone_number'   => '254712345678',
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
        'application_number'   => 'APP-TEST-44A',
        'borrower_id'          => $this->borrower->id,
        'loan_product_id'      => $this->product->id,
        'requested_principal'  => '10000.00',
        'requested_tenure_days'=> 30,
        'status'               => 'approved',
    ]);

    $this->loan = Loan::create([
        'loan_number'         => 'LN-TEST-44A-01',
        'loan_application_id' => $this->application->id,
        'borrower_id'         => $this->borrower->id,
        'loan_product_id'     => $this->product->id,
        'principal_disbursed' => '10000.00',
        'annual_interest_rate'=> '18.00',
        'tenure_days'         => 30,
        'status'              => LoanStatus::DISBURSED,
        'disbursed_at'        => CarbonImmutable::now()->subDays(100),
        'due_date'            => CarbonImmutable::now()->subDays(70),
        'is_in_duplum_locked' => false,
    ]);

    $this->service = app(InDuplumEnforcementService::class);
});

it('captures an immutable default principal snapshot upon entering default', function (): void {
    $tracking = $this->service->snapshotDefaultPrincipal($this->loan);

    expect($tracking)->toBeInstanceOf(InDuplumTracking::class)
        ->and($tracking->default_principal_snapshot)->toBe('10000.00')
        ->and($tracking->statutory_cap_limit)->toBe('10000.00')
        ->and($tracking->is_frozen)->toBeFalse();

    $this->loan->refresh();
    expect($this->loan->status)->toBe(LoanStatus::DEFAULTED);
});

it('accurately computes remaining in-duplum cap headroom using bcmath', function (): void {
    $this->service->snapshotDefaultPrincipal($this->loan);

    // Initial headroom must equal the snapshot (10,000.00)
    expect($this->service->getRemainingCapHeadroom($this->loan))->toBe('10000.00');

    // Accrue 3,500.00 interest
    $this->service->recordPostDefaultAccrual($this->loan, '3500.00', 'interest');
    expect($this->service->getRemainingCapHeadroom($this->loan))->toBe('6500.00');

    // Accrue 1,500.00 penalties
    $this->service->recordPostDefaultAccrual($this->loan, '1500.00', 'penalties');
    expect($this->service->getRemainingCapHeadroom($this->loan))->toBe('5000.00');
});

it('throws InDuplumCapExceededException when an attempted accrual exceeds the cap', function (): void {
    $this->service->snapshotDefaultPrincipal($this->loan);

    // Try to accrue 10,000.01 against a 10,000.00 cap
    $this->service->recordPostDefaultAccrual($this->loan, '10000.01', 'interest');
})->throws(InDuplumCapExceededException::class);

it('automatically freezes loan and emits an outbox event when the cap is reached', function (): void {
    $this->service->snapshotDefaultPrincipal($this->loan);

    // Accrue exactly the remaining 10,000.00 cap
    $this->service->recordPostDefaultAccrual($this->loan, '10000.00', 'interest');

    $this->loan->refresh();
    $tracking = InDuplumTracking::where('loan_id', $this->loan->id)->first();

    expect($this->loan->is_in_duplum_locked)->toBeTrue()
        ->and($tracking->is_frozen)->toBeTrue()
        ->and($tracking->frozen_at)->not->toBeNull();

    // Verify outbox event creation with SHA-256 hash
    $outbox = OutboxEvent::where('aggregate_id', $this->loan->id)
        ->where('event_name', 'loan.in_duplum_frozen')
        ->first();

    expect($outbox)->not->toBeNull()
        ->and($outbox->current_event_hash)->toHaveLength(64);
});

it('blocks any further accrual on already frozen loans', function (): void {
    $this->service->snapshotDefaultPrincipal($this->loan);
    $this->service->enforceStatutoryFreeze($this->loan, 'Manual compliance test freeze');

    // Attempting even 0.01 must fail
    $this->service->assertCanAccrue($this->loan, '0.01', 'interest');
})->throws(InDuplumCapExceededException::class);

it('artisan command successfully audits and displays in-duplum tracking status', function (): void {
    $this->service->snapshotDefaultPrincipal($this->loan);
    $this->service->recordPostDefaultAccrual($this->loan, '4000.00', 'interest');

    $this->artisan('loans:enforce-in-duplum --dry-run')
        ->assertSuccessful()
        ->expectsOutputToContain('Starting Section 44A In-Duplum Portfolio Audit')
        ->expectsOutputToContain('Wanjiku Kamau')
        ->expectsOutputToContain('DRY RUN');
});