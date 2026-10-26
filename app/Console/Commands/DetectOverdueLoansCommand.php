<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Servicing\OverdueDetectionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class DetectOverdueLoansCommand extends Command
{
    protected $signature = 'loans:detect-overdue {--date= : YYYY-MM-DD, local/testing only}';

    protected $description = 'Opens collection cases for overdue loans, moves DPD buckets and marks default at 90 days past due';

    public function handle(OverdueDetectionService $service): int
    {
        $asOf = null;
        $date = $this->option('date');

        if ($date !== null && $date !== '') {
            if (! app()->environment(['local', 'testing'])) {
                $this->error('--date is only allowed in local or testing environments.');

                return self::FAILURE;
            }

            try {
                $asOf = CarbonImmutable::createFromFormat('Y-m-d', (string) $date, 'Africa/Nairobi');
            } catch (Throwable) {
                $asOf = null;
            }

            if ($asOf === null) {
                $this->error('Use the format YYYY-MM-DD.');

                return self::FAILURE;
            }

            $asOf = $asOf->setTime(12, 0);
        }

        $stats = $service->detectAll($asOf);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Loans checked', $stats['checked']],
                ['Overdue', $stats['overdue']],
                ['Cases opened', $stats['opened']],
                ['Buckets moved', $stats['updated']],
                ['Moved to default', $stats['defaulted']],
                ['Failed', $stats['failed']],
            ]
        );

        $this->info('Overdue detection complete');

        return self::SUCCESS;
    }
}