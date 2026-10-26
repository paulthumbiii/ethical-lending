<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Servicing\PromiseToPayService;
use Illuminate\Console\Command;

final class ReviewPromisesCommand extends Command
{
    protected $signature = 'loans:review-promises';

    protected $description = 'Marks pending promises to pay as honored or broken';

    public function handle(PromiseToPayService $service): int
    {
        $stats = $service->reviewAll();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Promises checked', $stats['checked']],
                ['Honored', $stats['honored']],
                ['Broken', $stats['broken']],
                ['Failed', $stats['failed']],
            ]
        );

        $this->info('Promise review complete');

        return self::SUCCESS;
    }
}