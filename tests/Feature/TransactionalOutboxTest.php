<?php

declare(strict_types=1);

use App\Models\Loan;
use App\Models\OutboxEvent;
use App\Services\Financial\TransactionalOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = app(TransactionalOutboxService::class);
});

it('records outbox events with cryptographic sha256 hash chaining', function (): void {
    // 1. Genesis Event
    $event1 = $this->service->recordEvent(
        aggregateType: Loan::class,
        aggregateId: 101,
        eventName: 'loan.disbursed',
        payload: ['loan_id' => 101, 'amount' => '10000.00']
    );

    // Genesis block must have 64 zeros as previous hash
    expect($event1->previous_event_hash)->toBe(str_repeat('0', 64))
        ->and($event1->current_event_hash)->toHaveLength(64)
        ->and($event1->is_published)->toBeFalse();

    // 2. Second Event Chained to Genesis
    $event2 = $this->service->recordEvent(
        aggregateType: Loan::class,
        aggregateId: 101,
        eventName: 'loan.repayment_received',
        payload: ['loan_id' => 101, 'amount' => '2500.00']
    );

    // Event 2 previous hash MUST equal Event 1 current hash!
    expect($event2->previous_event_hash)->toBe($event1->current_event_hash)
        ->and($event2->current_event_hash)->toHaveLength(64);

    // 3. Third Event Chained to Event 2
    $event3 = $this->service->recordEvent(
        aggregateType: Loan::class,
        aggregateId: 101,
        eventName: 'loan.settled',
        payload: ['loan_id' => 101, 'status' => 'SETTLED']
    );

    expect($event3->previous_event_hash)->toBe($event2->current_event_hash);

    // Verify entire chain integrity
    $integrity = $this->service->verifyChainIntegrity();
    expect($integrity['is_valid'])->toBeTrue()
        ->and($integrity['total_verified'])->toBe(3)
        ->and($integrity['broken_event_id'])->toBeNull();
});

it('publishes pending outbox events successfully', function (): void {
    $this->service->recordEvent(Loan::class, 102, 'loan.disbursed', ['amount' => '5000.00']);
    $this->service->recordEvent(Loan::class, 102, 'loan.in_duplum_frozen', ['reason' => 'Cap reached']);

    expect(OutboxEvent::where('is_published', false)->count())->toBe(2);

    $publishedCount = $this->service->publishPendingEvents(50);

    expect($publishedCount)->toBe(2)
        ->and(OutboxEvent::where('is_published', true)->count())->toBe(2)
        ->and(OutboxEvent::where('is_published', false)->count())->toBe(0);
});

it('detects tampering when historical event payload is modified', function (): void {
    $this->service->recordEvent(Loan::class, 103, 'loan.disbursed', ['amount' => '10000.00']);
    $event2 = $this->service->recordEvent(Loan::class, 103, 'loan.repayment', ['amount' => '3000.00']);

    // Tamper with Event 2 payload directly in database (simulating malicious database manipulation)
    DB::table('outbox_events')
        ->where('id', $event2->id)
        ->update([
            'payload' => json_encode(['amount' => '99999.00']), // Tampered amount!
        ]);

    $integrity = $this->service->verifyChainIntegrity();

    // Verification must catch the tampering immediately!
    expect($integrity['is_valid'])->toBeFalse()
        ->and($integrity['broken_event_id'])->toBe($event2->id);
});

it('artisan command outbox:publish executes and reports status', function (): void {
    $this->service->recordEvent(Loan::class, 104, 'loan.disbursed', ['amount' => '15000.00']);

    // 1. Test audit chain verification CLI flag
    $this->artisan('outbox:publish --verify-chain')
        ->assertSuccessful()
        ->expectsOutputToContain('Cryptographic chain verified across all 1 event(s)');

    // 2. Test publishing CLI mode
    $this->artisan('outbox:publish --batch=10')
        ->assertSuccessful()
        ->expectsOutputToContain('Published 1 outbox event(s) successfully.');

    expect(OutboxEvent::where('is_published', true)->count())->toBe(1);
});