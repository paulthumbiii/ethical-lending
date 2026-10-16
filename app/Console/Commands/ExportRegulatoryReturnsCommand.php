<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Compliance\CbkStatutoryReturnService;
use App\Services\Compliance\CrbMetro2ExporterService;
use Illuminate\Console\Command;

final class ExportRegulatoryReturnsCommand extends Command
{
    protected $signature = 'loans:export-regulatory-returns {period? : YYYY-MM format}';
    protected $description = 'Generates monthly CBK statutory returns and exports CRB Metro 2 files';

    public function handle(
        CbkStatutoryReturnService $cbkService,
        CrbMetro2ExporterService $crbService
    ): int {
        $period = $this->argument('period') ?? date('Y-m');

        $this->info("Compiling CBK Statutory Returns for period: {$period}...");
        $cbkReturn = $cbkService->generateMonthlyReturn($period);
        $this->info("CBK Return generated successfully. ID: {$cbkReturn->id}");

        $this->info("Exporting CRB Metro 2 files...");
        foreach (['METROPOL', 'CREDITINFO', 'TRANSUNION'] as $bureau) {
            $export = $crbService->exportMetro2Data($bureau);
            $this->info(" -> {$bureau}: {$export->records_count} records exported.");
        }

        $this->info("Regulatory export process complete.");

        return Command::SUCCESS;
    }
}