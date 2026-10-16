<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Exceptions\Fintech\AccountingPeriodClosedException;
use App\Exceptions\Fintech\UnbalancedJournalEntryException;
use App\Models\AccountingPeriod;
use App\Models\AuditTrail;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalItem;
use App\Models\Loan;
use App\Models\TaxWithholdingLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class GeneralLedgerService
{
    /**
     * Posts a balanced double-entry journal entry to the General Ledger.
     *
     * @param array<int, array{account_code: string, debit: string, credit: string}> $items
     */
    public function postBalancedEntry(
        string $description,
        array $items,
        ?string $referenceType = null,
        ?int $referenceId = null
    ): JournalEntry {
        return DB::transaction(function () use ($description, $items, $referenceType, $referenceId): JournalEntry {
            $now = CarbonImmutable::now();

            // 1. Determine and validate active accounting period
            $period = AccountingPeriod::query()
                ->where('start_date', '<=', $now->toDateString())
                ->where('end_date', '>=', $now->toDateString())
                ->first();

            if ($period === null) {
                // Auto-create standard monthly period if none initialized
                $period = AccountingPeriod::create([
                    'period_name' => $now->format('F Y'),
                    'start_date'  => $now->startOfMonth()->toDateString(),
                    'end_date'    => $now->endOfMonth()->toDateString(),
                    'is_locked'   => false,
                ]);
            }

            if ($period->is_locked) {
                throw new AccountingPeriodClosedException($period->period_name);
            }

            // 2. Validate Double-Entry Invariant: Sum(Debits) === Sum(Credits)
            $totalDebit = '0.00';
            $totalCredit = '0.00';

            foreach ($items as $item) {
                $totalDebit = bcadd($totalDebit, (string) $item['debit'], 2);
                $totalCredit = bcadd($totalCredit, (string) $item['credit'], 2);
            }

            if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
                throw new UnbalancedJournalEntryException($totalDebit, $totalCredit);
            }

            // 3. Create Journal Entry Header
            $journalEntry = JournalEntry::create([
                'entry_number'         => 'JRN-' . strtoupper(Str::random(10)),
                'accounting_period_id' => $period->id,
                'reference_type'       => $referenceType,
                'reference_id'         => $referenceId,
                'description'          => $description,
                'posted_at'            => $now,
                'created_by_user_id'   => auth()->id(),
            ]);

            // 4. Create Individual Balanced Ledger Items
            foreach ($items as $item) {
                $account = ChartOfAccount::query()
                    ->where('account_code', $item['account_code'])
                    ->firstOrFail();

                JournalItem::create([
                    'journal_entry_id'    => $journalEntry->id,
                    'chart_of_account_id' => $account->id,
                    'debit'               => (string) $item['debit'],
                    'credit'              => (string) $item['credit'],
                ]);
            }

            // 5. Record Audit Trail
            AuditTrail::create([
                'user_id'        => auth()->id(),
                'action'         => 'JOURNAL_ENTRY_POSTED',
                'auditable_type' => JournalEntry::class,
                'auditable_id'   => $journalEntry->id,
                'old_values'     => null,
                'new_values'     => [
                    'entry_number' => $journalEntry->entry_number,
                    'total_debit'  => $totalDebit,
                    'total_credit' => $totalCredit,
                ],
                'ip_address'     => request()->ip() ?? '127.0.0.1',
                'user_agent'     => request()->userAgent() ?? 'System/CLI',
            ]);

            return $journalEntry;
        });
    }

    /**
     * Records Loan Disbursement:
     * DEBIT:  1200 - Loans Receivable (Asset increases)
     * CREDIT: 1010 - M-Pesa Settlement Account (Asset decreases)
     */
    public function recordLoanDisbursementPosting(Loan $loan, string $amount): JournalEntry
    {
        return $this->postBalancedEntry(
            description: sprintf('Loan disbursement for Loan #%s to borrower #%d', $loan->loan_number, $loan->borrower_id),
            items: [
                ['account_code' => '1200', 'debit' => $amount, 'credit' => '0.00'],
                ['account_code' => '1010', 'debit' => '0.00', 'credit' => $amount],
            ],
            referenceType: Loan::class,
            referenceId: $loan->id
        );
    }

    /**
     * Records Fee Accrual with 20% KRA Excise Duty Split (Excise Duty Act):
     * DEBIT:  1200 - Loans Receivable (Borrower owes fee)
     * CREDIT: 4020 - Fee Income (80% net revenue)
     * CREDIT: 2030 - KRA Excise Duty Payable (20% statutory tax liability)
     */
    public function recordFeeWithExcisePosting(Loan $loan, string $feeName, string $feeAmount): JournalEntry
    {
        // 20% statutory rate: Excise = Fee * 0.20
        $exciseRate = '20.00';
        $exciseAmount = bcmul($feeAmount, '0.20', 2);
        $netFeeIncome = bcsub($feeAmount, $exciseAmount, 2);

        // Record tax entry for KRA reporting
        TaxWithholdingLedger::create([
            'loan_id'            => $loan->id,
            'fee_name'           => $feeName,
            'fee_charged'        => $feeAmount,
            'excise_rate'        => $exciseRate,
            'excise_duty_amount' => $exciseAmount,
            'remittance_status'  => 'accrued',
            'remitted_at'        => null,
        ]);

        return $this->postBalancedEntry(
            description: sprintf('Fee [%s] with 20%% Excise Duty on Loan #%s', $feeName, $loan->loan_number),
            items: [
                ['account_code' => '1200', 'debit' => $feeAmount, 'credit' => '0.00'],
                ['account_code' => '4020', 'debit' => '0.00', 'credit' => $netFeeIncome],
                ['account_code' => '2030', 'debit' => '0.00', 'credit' => $exciseAmount],
            ],
            referenceType: Loan::class,
            referenceId: $loan->id
        );
    }

    /**
     * Records Inbound Repayment Posting from M-Pesa:
     * DEBIT:  1010 - M-Pesa Settlement Account (Total Cash Received)
     * CREDIT: 1200 - Loans Receivable (Principal repaid)
     * CREDIT: 4010 - Interest Income (Interest cleared)
     * CREDIT: 4020 - Fee Income (Fees cleared)
     * CREDIT: 2010 - Customer Overpayment Credits (Overpaid cash liability, if any)
     */
    public function recordRepaymentPosting(
        Loan $loan,
        string $feesPaid,
        string $interestPaid,
        string $principalPaid,
        string $overpayment,
        string $reference
    ): JournalEntry {
        $totalCash = bcadd(bcadd(bcadd($feesPaid, $interestPaid, 2), $principalPaid, 2), $overpayment, 2);

        $items = [
            ['account_code' => '1010', 'debit' => $totalCash, 'credit' => '0.00'],
        ];

        if (bccomp($principalPaid, '0.00', 2) > 0) {
            $items[] = ['account_code' => '1200', 'debit' => '0.00', 'credit' => $principalPaid];
        }

        if (bccomp($interestPaid, '0.00', 2) > 0) {
            $items[] = ['account_code' => '4010', 'debit' => '0.00', 'credit' => $interestPaid];
        }

        if (bccomp($feesPaid, '0.00', 2) > 0) {
            $items[] = ['account_code' => '4020', 'debit' => '0.00', 'credit' => $feesPaid];
        }

        if (bccomp($overpayment, '0.00', 2) > 0) {
            $items[] = ['account_code' => '2010', 'debit' => '0.00', 'credit' => $overpayment];
        }

        return $this->postBalancedEntry(
            description: sprintf('Repayment received via M-Pesa [Ref: %s] for Loan #%s', $reference, $loan->loan_number),
            items: $items,
            referenceType: Loan::class,
            referenceId: $loan->id
        );
    }
}