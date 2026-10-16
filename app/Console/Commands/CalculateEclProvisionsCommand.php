<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\Ifrs9ProvisioningService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class CalculateEclProvisionsCommand extends Command
{
    protected $signature = 'loans:calculate-ecl {--date= : Custom evaluation date (YYYY-MM-DD)}';

    protected $description = 'Executes statutory IFRS 9 staging and Expected Credit Loss (ECL) portfolio calculations';

    public function handle(Ifrs9ProvisioningService $service): int
    {
        $this->info('Starting IFRS 9 Expected Credit Loss (ECL) Portfolio Evaluation...');

        $dateInput = $this->option('date');
        $asOfDate = $dateInput !== null
            ? CarbonImmutable::parse($dateInput)
            : CarbonImmutable::now();

        $this->line(sprintf('Evaluation Reporting Date: <fg=yellow;options=bold>%s</>', $asOfDate->toDateString()));

        $result = $service->computePortfolioEcl($asOfDate);

        $this->table(
            ['IFRS 9 Staging Classification', 'Provision Reserve (KES)'],
            [
                ['Stage 1 (Performing - 12M ECL)', 'KES ' . number_format((float) $result['stage_1_provisions'], 2)],
                ['Stage 2 (Underperforming / SICR - Lifetime ECL)', 'KES ' . number_format((float) $result['stage_2_provisions'], 2)],
                ['Stage 3 (Credit-Impaired / Defaulted - Lifetime ECL)', 'KES ' . number_format((float) $result['stage_3_provisions'], 2)],
                ['<fg=green;options=bold>TOTAL LOAN-LOSS RESERVE REQUIRED</>', '<fg=green;options=bold>KES ' . number_format((float) $result['total_ecl_reserve'], 2) . '</>'],
            ]
        );

        $this->info(sprintf(
            'IFRS 9 Evaluation Completed successfully for %d active loan(s).',
            $result['total_loans_evaluated']
        ));

        return self::SUCCESS;
    }
}