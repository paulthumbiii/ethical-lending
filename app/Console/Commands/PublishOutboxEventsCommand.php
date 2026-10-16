<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Financial\TransactionalOutboxService;
use Illuminate\Console\Command;

final class PublishOutboxEventsCommand extends Command
{
    protected $signature = 'outbox:publish 
                            {--batch=50 : Number of pending events to process}
                            {--verify-chain : Run cryptographic SHA-256 chain verification instead of publishing}';

    protected $description = 'Dispatches pending Transactional Outbox events and verifies cryptographic audit chain integrity';

    public function handle(TransactionalOutboxService $service): int
    {
        // Audit Mode: Verify chain integrity
        if ($this->option('verify-chain')) {
            $this->info('Starting cryptographic SHA-256 audit chain verification...');
            $result = $service->verifyChainIntegrity();

            if ($result['is_valid']) {
                $this->info(sprintf(
                    '<fg=green;options=bold>PASS:</> Cryptographic chain verified across all %d event(s). Zero tampering detected.',
                    $result['total_verified']
                ));
                return self::SUCCESS;
            }

            $this->error(sprintf(
                '<fg=red;options=bold>CRITICAL AUDIT BREACH:</> Hash chain broken at OutboxEvent #%d! Data tampering detected.',
                $result['broken_event_id']
            ));
            return self::FAILURE;
        }

        // Publishing Mode: Drain pending events
        $this->info('Dispatching pending transactional outbox events...');
        $batch = (int) $this->option('batch');
        $published = $service->publishPendingEvents($batch);

        $this->info(sprintf('Published %d outbox event(s) successfully.', $published));

        return self::SUCCESS;
    }
}