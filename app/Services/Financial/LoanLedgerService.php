<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Models\Loan;
use App\Models\LoanLedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class LoanLedgerService
{
    /**
     * Posts an append-only transaction entry to the immutable loan ledger.
     * Calculates atomic point-in-time running balances under pessimistic row locking.
     *
     * @param int $loanId
     * @param string $entryType (e.g., 'DISBURSEMENT_PRINCIPAL', 'INTEREST_ACCRUAL', 'PENALTY_CHARGED', 'REPAYMENT_CREDIT', 'WAIVER_CREDIT')
     * @param string $debit Amount debited to borrower account (increases debt)
     * @param string $credit Amount credited to borrower account (decreases debt)
     * @param string $reference Transaction or external payment reference code
     * @param string|null $notes Contextual description
     * @return LoanLedgerEntry
     */
    public function recordEntry(
        int $loanId,
        string $entryType,
        string $debit = '0.00',
        string $credit = '0.00',
        string $reference = '',
        ?string $notes = null
    ): LoanLedgerEntry {
        return DB::transaction(function () use ($loanId, $entryType, $debit, $credit, $reference, $notes) {
            /** @var Loan $loan */
            $loan = Loan::where('id', $loanId)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var LoanLedgerEntry|null $latestEntry */
            $latestEntry = LoanLedgerEntry::where('loan_id', $loan->id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            // Fetch running balances or default to zero
            $prevPrincipal = $latestEntry ? (string) $latestEntry->running_principal_balance : '0.00';
            $prevInterest  = $latestEntry ? (string) $latestEntry->running_interest_balance : '0.00';
            $prevFees      = $latestEntry ? (string) $latestEntry->running_fees_balance : '0.00';

            // Calculate new balances based on entry type
            $newPrincipal = $prevPrincipal;
            $newInterest  = $prevInterest;
            $newFees      = $prevFees;

            switch ($entryType) {
                case 'DISBURSEMENT_PRINCIPAL':
                    $newPrincipal = bcadd($prevPrincipal, $debit, 2);
                    break;

                case 'INTEREST_ACCRUAL':
                    $newInterest = bcadd($prevInterest, $debit, 2);
                    break;

                case 'PENALTY_CHARGED':
                case 'FEE_CHARGED':
                    $newFees = bcadd($prevFees, $debit, 2);
                    break;

                case 'REPAYMENT_PRINCIPAL_CREDIT':
                    $newPrincipal = bcsub($prevPrincipal, $credit, 2);
                    break;

                case 'REPAYMENT_INTEREST_CREDIT':
                    $newInterest = bcsub($prevInterest, $credit, 2);
                    break;

                case 'REPAYMENT_FEES_CREDIT':
                    $newFees = bcsub($prevFees, $credit, 2);
                    break;

                case 'WAIVER_INTEREST_CREDIT':
                    $newInterest = bcsub($prevInterest, $credit, 2);
                    break;

                case 'WAIVER_FEES_CREDIT':
                    $newFees = bcsub($prevFees, $credit, 2);
                    break;
            }

            // Ensure balances do not drop below zero
            $newPrincipal = bccomp($newPrincipal, '0.00', 2) < 0 ? '0.00' : $newPrincipal;
            $newInterest  = bccomp($newInterest, '0.00', 2) < 0 ? '0.00' : $newInterest;
            $newFees      = bccomp($newFees, '0.00', 2) < 0 ? '0.00' : $newFees;

            $totalRunningBalance = bcadd(bcadd($newPrincipal, $newInterest, 2), $newFees, 2);

            /** @var LoanLedgerEntry $entry */
            $entry = LoanLedgerEntry::create([
                'loan_id'                   => $loan->id,
                'entry_type'                => $entryType,
                'debit'                     => $debit,
                'credit'                    => $credit,
                'running_principal_balance' => $newPrincipal,
                'running_interest_balance'  => $newInterest,
                'running_fees_balance'      => $newFees,
                'total_running_balance'     => $totalRunningBalance,
                'reference'                 => $reference,
                'notes'                     => $notes,
            ]);

            Log::channel('fintech')->info("Loan Ledger Entry Posted: Loan #{$loan->id} [Type: {$entryType}, Total Running Balance: KES {$totalRunningBalance}]");

            return $entry;
        });
    }
}