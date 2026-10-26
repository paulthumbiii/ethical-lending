<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Servicing\InterestAccrualService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class AccrueInterestCommand extends Command
{
    protected $signature = 'loans:accrue-interest {--date= : Local/testing only. Accrue as of this date (YYYY-MM-DD)}';

    protected $description = 'Adds interest owed up to today to each active loan ledger (safe to run more than once a day)';

    public function handle(InterestAccrualService $service): int
    {
        $asOf = null;

        if ($this->option('date') !== null) {
            if (! app()->environment(['local', 'testing'])) {
                $this->error('The --date option is only allowed in local or testing environments.');

                return self::FAILURE;
            }

            try {
                $asOf = CarbonImmutable::parse((string) $this->option('date'), 'Africa/Nairobi')->setTime(12, 0);
            } catch (Throwable) {
                $this->error('That date is not valid. Use YYYY-MM-DD.');

                return self::FAILURE;
            }

            $this->warn('Accruing as of ' . $asOf->format('j M Y') . ' (demo only).');
        }

        $result = $service->accrueAll($asOf);

        $this->table(['Metric', 'Value'], [
            ['Loans checked', $result['checked']],
            ['Loans that received interest', $result['posted']],
            ['Interest added (KES)', number_format((float) $result['total'], 2)],
            ['Loans that failed', $result['failed']],
        ]);

        $this->info('Interest accrual complete.');

        return self::SUCCESS;
    }
}