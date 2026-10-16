<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Models\CrbDisputeLock;
use App\Models\CrbReportExport;
use App\Models\Loan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class CrbMetro2ExporterService
{
    /**
     * Exports non-disputed borrower credit records into CRB Metro 2 pipe-delimited format.
     *
     * @param string $bureauName (e.g., 'METROPOL', 'CREDITINFO', 'TRANSUNION')
     * @return CrbReportExport
     */
    public function exportMetro2Data(string $bureauName): CrbReportExport
    {
        return DB::transaction(function () use ($bureauName) {
            // Get all active dispute loan IDs (unresolved locks) to exclude from reporting
            $disputedLoanIds = CrbDisputeLock::whereNull('resolved_at')
                ->pluck('loan_id')
                ->filter()
                ->toArray();

            $loans = Loan::with('borrower')
                ->whereNotIn('id', $disputedLoanIds)
                ->get();

            $records = [];
            $exportedCount = 0;

            foreach ($loans as $loan) {
                $borrower = $loan->borrower;
                if (! $borrower) {
                    continue;
                }

                // Format: NAT_ID|FULL_NAME|PHONE|LOAN_NUM|PRINCIPAL|STATUS
                $line = sprintf(
                    '%s|%s|%s|%s|%s|%s',
                    $borrower->id_number,
                    $borrower->full_name,
                    $borrower->phone_number,
                    $loan->loan_number,
                    $loan->principal_disbursed,
                    $loan->status->value
                );

                $records[] = $line;
                $exportedCount++;
            }

            /** @var CrbReportExport $export */
            $export = CrbReportExport::create([
                'bureau_name' => strtoupper($bureauName),
                'export_reference' => 'CRB_' . strtoupper($bureauName) . '_' . date('YmdHis'),
                'records_count' => $exportedCount,
                'payload_summary' => [
                    'bureau' => $bureauName,
                    'total_exported' => $exportedCount,
                    'disputed_excluded' => count($disputedLoanIds),
                ],
                'exported_at' => now(),
            ]);

            Log::channel('fintech')->info("CRB Metro 2 Data exported for {$bureauName}: {$exportedCount} records included, " . count($disputedLoanIds) . " disputed excluded.");

            return $export;
        });
    }
}