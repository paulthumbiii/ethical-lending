<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Exceptions\Fintech\RegulatoryCooldownActiveException;
use App\Models\Borrower;
use App\Models\BorrowerOptOut;
use App\Models\CollectionAction;
use App\Models\CollectionCase;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\OutboxEvent;
use App\Models\PromiseToPay;
use App\Models\RegulatoryCooldownLock;
use App\Services\Servicing\FairCollectionsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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
        'application_number'   => 'APP-COLL-01',
        'borrower_id'          => $this->borrower->id,
        'loan_product_id'      => $this->product->id,
        'requested_principal'  => '10000.00',
        'requested_tenure_days'=> 30,
        'status'               => 'approved',
    ]);

    $this->loan = Loan::create([
        'loan_number'         => 'LN-COLL-01',
        'loan_application_id' => $this->application->id,
        'borrower_id'         => $this->borrower->id,
        'loan_product_id'     => $this->product->id,
        'principal_disbursed' => '10000.00',
        'annual_interest_rate'=> '18.00',
        'tenure_days'         => 30,
        'status'              => LoanStatus::DISBURSED,
        'disbursed_at'        => CarbonImmutable::now()->subDays(40),
        'due_date'            => CarbonImmutable::now()->subDays(10), // 10 days overdue!
        'is_in_duplum_locked' => false,
    ]);

    $this->service = app(FairCollectionsService::class);
});

it('dispatches ethical collection reminder and applies 48-hour lock during legal hours', function (): void {
    // 10:30 AM EAT (which is 07:30 UTC) - inside statutory 08:00-18:00 window!
    $legalTime = CarbonImmutable::parse('2026-10-16 10:30:00', 'Africa/Nairobi')->utc();

    $action = $this->service->processCollectionEscalation($this->loan, $legalTime);

    expect($action)->toBeInstanceOf(CollectionAction::class)
        ->and($action->action_type)->toBe('SMS')
        ->and($action->is_compliant_hours)->toBeTrue()
        ->and($action->message_payload)->toContain('Wanjiku');

    // Assert 48-hour cooldown lock created
    $lock = RegulatoryCooldownLock::where('borrower_id', $this->borrower->id)->first();
    expect($lock)->not->toBeNull()
        ->and($lock->lock_type)->toBe('COLLECTIONS_SMS')
        ->and((int) $legalTime->diffInHours($lock->locked_until))->toBe(48);

    // Assert Outbox Event queued for Africa's Talking SMS
    $outbox = OutboxEvent::where('event_name', 'collection.sms_queued')->first();
    expect($outbox)->not->toBeNull()
        ->and($outbox->payload['phone_number'])->toBe('254712345678');
});

it('completely blocks outreach outside legal statutory hours', function (): void {
    // 21:30 PM EAT (Outside legal 08:00 - 18:00 window!)
    $nightTime = CarbonImmutable::parse('2026-10-16 21:30:00', 'Africa/Nairobi')->utc();

    $action = $this->service->processCollectionEscalation($this->loan, $nightTime);

    // Must be null - zero messages allowed!
    expect($action)->toBeNull();
    expect(CollectionAction::count())->toBe(0);
    expect(RegulatoryCooldownLock::count())->toBe(0);
});

it('throws RegulatoryCooldownActiveException if contacted within 48-hour quiet period', function (): void {
    $time1 = CarbonImmutable::parse('2026-10-16 09:00:00', 'Africa/Nairobi')->utc();
    // 4 hours later: 13:00 (1 PM EAT) - inside legal hours, but within the 48-hour quiet period!
    $time2 = $time1->addHours(4);

    // First contact succeeds
    $this->service->processCollectionEscalation($this->loan, $time1);

    // Second contact within 48 hours must throw exception
    $this->service->processCollectionEscalation($this->loan, $time2);
})->throws(RegulatoryCooldownActiveException::class);

it('pauses automated collection escalation if borrower has active Promise to Pay', function (): void {
    $legalTime = CarbonImmutable::parse('2026-10-16 11:00:00', 'Africa/Nairobi')->utc();

    $case = CollectionCase::create([
        'loan_id'           => $this->loan->id,
        'borrower_id'       => $this->borrower->id,
        'dpd_bucket'        => 'DPD_1_30',
        'case_status'       => 'open',
        'last_contacted_at' => null,
    ]);

    // Borrower promised to pay tomorrow
    PromiseToPay::create([
        'collection_case_id' => $case->id,
        'promised_date'      => $legalTime->addDay()->toDateString(),
        'promised_amount'    => '5000.00',
        'status'             => 'pending',
    ]);

    $action = $this->service->processCollectionEscalation($this->loan, $legalTime);

    // Escalation paused!
    expect($action)->toBeNull();
    expect(CollectionAction::count())->toBe(0);
});

it('skips borrowers with opt-out on file under Data Protection Act', function (): void {
    $legalTime = CarbonImmutable::parse('2026-10-16 14:00:00', 'Africa/Nairobi')->utc();

    BorrowerOptOut::create([
        'borrower_id'    => $this->borrower->id,
        'channel'        => 'SMS',
        'opt_out_reason' => 'Requested opt-out from automated reminders',
        'opted_out_at'   => CarbonImmutable::now(),
    ]);

    $action = $this->service->processCollectionEscalation($this->loan, $legalTime);

    expect($action)->toBeNull();
});

it('artisan command process-collections halts safely outside legal hours', function (): void {
    // Simulate running at 22:00 EAT (10 PM)
    $this->artisan('loans:process-collections --force-hour=22')
        ->assertSuccessful()
        ->expectsOutputToContain('HALTED: Current time [22:00 EAT] is outside statutory contact hours');
});