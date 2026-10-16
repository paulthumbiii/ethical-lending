<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Models\AuditTrail;
use App\Models\OutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

final class TransactionalOutboxService
{
    /**
     * Records an outbox event with SHA-256 cryptographic chaining under a pessimistic lock.
     *
     * @param array<string, mixed> $payload
     */
    public function recordEvent(
        string $aggregateType,
        int $aggregateId,
        string $eventName,
        array $payload
    ): OutboxEvent {
        return DB::transaction(function () use ($aggregateType, $aggregateId, $eventName, $payload): OutboxEvent {
            // Lock the latest event to prevent race-condition hash forks
            $previousEvent = OutboxEvent::query()->latest('id')->lockForUpdate()->first();
            $previousHash = $previousEvent !== null ? (string) $previousEvent->current_event_hash : str_repeat('0', 64);

            $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            // Chained Hash: SHA256(prev_hash + aggregate_type + aggregate_id + event_name + payload)
            $currentHash = hash('sha256', $previousHash . $aggregateType . $aggregateId . $eventName . $payloadJson);

            $event = OutboxEvent::create([
                'aggregate_type'      => $aggregateType,
                'aggregate_id'        => $aggregateId,
                'event_name'          => $eventName,
                'payload'             => $payload,
                'previous_event_hash' => $previousHash,
                'current_event_hash'  => $currentHash,
                'is_published'        => false,
                'published_at'        => null,
                'retry_count'         => 0,
                'last_error'          => null,
            ]);

            AuditTrail::create([
                'user_id'        => auth()->id(),
                'action'         => 'OUTBOX_EVENT_COMMITTED',
                'auditable_type' => OutboxEvent::class,
                'auditable_id'   => $event->id,
                'old_values'     => null,
                'new_values'     => [
                    'event_name'   => $eventName,
                    'current_hash' => $currentHash,
                ],
                'ip_address'     => request()->ip() ?? '127.0.0.1',
                'user_agent'     => request()->userAgent() ?? 'System/CLI',
            ]);

            return $event;
        });
    }

    /**
     * Publishes pending outbox events to downstream listeners/handlers.
     */
    public function publishPendingEvents(int $batchSize = 50): int
    {
        $pendingEvents = OutboxEvent::query()
            ->where('is_published', false)
            ->where('retry_count', '<', 5)
            ->orderBy('id', 'asc')
            ->limit($batchSize)
            ->get();

        $publishedCount = 0;

        foreach ($pendingEvents as $event) {
            try {
                // Dispatch event through Laravel's Event bus
                Event::dispatch($event->event_name, [$event->payload]);

                $event->update([
                    'is_published' => true,
                    'published_at' => CarbonImmutable::now(),
                    'last_error'   => null,
                ]);

                $publishedCount++;
            } catch (Throwable $e) {
                $event->increment('retry_count');
                $event->update([
                    'last_error' => sprintf('[%s] %s', get_class($e), $e->getMessage()),
                ]);

                Log::channel('daily')->error(sprintf(
                    'Failed to publish OutboxEvent #%d (%s): %s',
                    $event->id,
                    $event->event_name,
                    $e->getMessage()
                ));
            }
        }

        return $publishedCount;
    }

    /**
     * Cryptographic chain validator: Walks the outbox to verify zero tampering.
     *
     * @return array{is_valid: bool, total_verified: int, broken_event_id: ?int}
     */
    public function verifyChainIntegrity(): array
    {
        $events = OutboxEvent::query()->orderBy('id', 'asc')->get();
        $expectedPrevHash = str_repeat('0', 64);
        $totalVerified = 0;

        foreach ($events as $event) {
            // Check 1: Does previous_event_hash match expected preceding hash?
            if ($event->previous_event_hash !== $expectedPrevHash) {
                return [
                    'is_valid'        => false,
                    'total_verified'  => $totalVerified,
                    'broken_event_id' => $event->id,
                ];
            }

            // Check 2: Re-compute current hash from payload
            $payloadJson = json_encode($event->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $recalculatedHash = hash(
                'sha256',
                $event->previous_event_hash . $event->aggregate_type . $event->aggregate_id . $event->event_name . $payloadJson
            );

            if ($event->current_event_hash !== $recalculatedHash) {
                return [
                    'is_valid'        => false,
                    'total_verified'  => $totalVerified,
                    'broken_event_id' => $event->id,
                ];
            }

            $expectedPrevHash = (string) $event->current_event_hash;
            $totalVerified++;
        }

        return [
            'is_valid'        => true,
            'total_verified'  => $totalVerified,
            'broken_event_id' => null,
        ];
    }
}