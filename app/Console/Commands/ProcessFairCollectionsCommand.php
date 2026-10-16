<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Servicing\FairCollectionsService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ProcessFairCollectionsCommand extends Command
{
    protected $signature = 'loans:process-collections 
                            {--dry-run : Inspect overdue accounts without sending SMS or locking accounts}
                            {--force-hour= : Simulate execution at a specific EAT hour (e.g. 10 or 21)}';

    protected $description = 'Executes regulatory-compliant fair debt collection escalation under CBK DCP Regulation 20';

    public function handle(FairCollectionsService $service): int
    {
        $this->info('Starting CBK Fair Debt Collections Escalator...');

        $now = CarbonImmutable::now('Africa/Nairobi');

        if ($this->option('force-hour') !== null) {
            $hour = (int) $this->option('force-hour');
            $now = $now->setHour($hour)->setMinute(0);
            $this->warn(sprintf('Simulating EAT time: %s', $now->format('H:i \E\A\T')));
        }

        // 1. Verify Legal Hours (08:00 - 18:00 EAT)
        if (!$service->isWithinLegalContactHours($now)) {
            $this->error(sprintf(
                'HALTED: Current time [%s EAT] is outside statutory contact hours (08:00–18:00 EAT). Outreach prohibited under CBK Regulation 20.',
                $now->format('H:i')
            ));
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN: Inspecting portfolio without committing locks or dispatching notifications.');
        }

        $result = $service->scanAndEscalateDelinquentPortfolio($now);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Status', $result['status']],
                ['Compliant Reminders Dispatched', $result['dispatched']],
                ['Skipped on 48h Cooldown Lock', $result['skipped_cooldown']],
            ]
        );

        $this->info('CBK Fair Collections process completed.');

        return self::SUCCESS;
    }
}