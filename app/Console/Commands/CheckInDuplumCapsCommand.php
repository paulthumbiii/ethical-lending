<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\InDuplumTracking;
use App\Services\Financial\InDuplumEnforcementService;
use Illuminate\Console\Command;

final class CheckInDuplumCapsCommand extends Command
{
    protected $signature = 'loans:enforce-in-duplum {--dry-run : Inspect portfolio without committing freeze locks}';

    protected $description = 'Scans defaulted loans and executes statutory freeze under Banking Act Section 44A (In-Duplum)';

    public function handle(InDuplumEnforcementService $service): int
    {
        $this->info('Starting Section 44A In-Duplum Portfolio Audit...');

        $trackings = InDuplumTracking::query()
            ->with(['loan.borrower'])
            ->get();

        if ($trackings->isEmpty()) {
            $this->line('No accounts currently have active In-Duplum default tracking.');
            return self::SUCCESS;
        }

        $rows = [];
        $freezeCandidates = 0;

        foreach ($trackings as $t) {
            $headroom = $service->getRemainingCapHeadroom($t->loan);
            $totalAccruals = bcadd((string) $t->total_post_default_interest, (string) $t->total_post_default_penalties, 2);

            $rows[] = [
                $t->loan_id,
                $t->loan->borrower->full_name ?? 'N/A',
                'KES ' . number_format((float) $t->default_principal_snapshot, 2),
                'KES ' . number_format((float) $totalAccruals, 2),
                'KES ' . number_format((float) $headroom, 2),
                $t->is_frozen ? '<fg=red;options=bold>FROZEN</>' : '<fg=green>ACTIVE</>',
            ];

            if (!$t->is_frozen && bccomp($headroom, '0.00', 2) <= 0) {
                $freezeCandidates++;
            }
        }

        $this->table(
            ['Loan ID', 'Borrower', 'Snapshot Cap', 'Post-Default Accrued', 'Remaining Headroom', 'Status'],
            $rows
        );

        if ($this->option('dry-run')) {
            $this->warn(sprintf('DRY RUN: %d loan(s) eligible for statutory freeze.', $freezeCandidates));
            return self::SUCCESS;
        }

        $frozenCount = $service->auditAndFreezeBreachedPortfolio();
        $this->info(sprintf('Audit complete: %d account(s) placed under In-Duplum statutory freeze.', $frozenCount));

        return self::SUCCESS;
    }
}