<?php

declare(strict_types=1);

namespace App\Services\Lending;

use App\Models\AuditTrail;
use App\Models\ChartOfAccount;
use App\Models\FeeMatrix;
use App\Models\Loan;
use App\Models\LoanLedgerEntry;
use App\Services\Financial\GeneralLedgerService;
use App\Services\Financial\LoanLedgerService;
use RuntimeException;

final class LoanFeeService
{
    public const LEDGER_ENTRY_TYPE = 'FEE_CHARGED';

    public function __construct(
        private readonly LoanLedgerService $ledger,
        private readonly GeneralLedgerService $gl
    ) {}

    /**
     * The fees a loan will be charged, worked out from its product's fee rules.
     * Fee types we do not understand are skipped, never guessed.
     *
     * @return list<array{name: string, amount: string, excise: bool}>
     */
    public function quote(Loan $loan): array
    {
        $principal = (string) $loan->principal_disbursed;
        $quote = [];

        $fees = FeeMatrix::query()
            ->where('loan_product_id', $loan->loan_product_id)
            ->orderBy('id')
            ->get();

        foreach ($fees as $fee) {
            $amount = match (strtolower((string) $fee->calculation_type)) {
                'percentage' => $this->percentOf($principal, (string) $fee->fee_percentage),
                'fixed', 'flat' => bcadd((string) $fee->fee_amount, '0', 2),
                default => null,
            };

            if ($amount === null || bccomp($amount, '0.00', 2) <= 0) {
                continue;
            }

            $quote[] = [
                'name'   => (string) $fee->fee_name,
                'amount' => $amount,
                'excise' => (bool) $fee->is_excise_duty_applicable,
            ];
        }

        return $quote;
    }

    /**
     * Adds the loan's fees to what the borrower owes. Call inside the
     * disbursement transaction. Does nothing if fees were already charged.
     *
     * @return list<array{name: string, amount: string, excise: bool}>
     */
    public function charge(Loan $loan, string $receipt): array
    {
        $alreadyCharged = LoanLedgerEntry::query()
            ->where('loan_id', $loan->id)
            ->where('entry_type', self::LEDGER_ENTRY_TYPE)
            ->exists();

        if ($alreadyCharged) {
            return [];
        }

        $charged = [];

        foreach ($this->quote($loan) as $index => $fee) {
            $reference = 'FEE-' . $receipt . '-' . ($index + 1);

            $notes = sprintf(
                '%s: KES %s%s',
                $fee['name'],
                $fee['amount'],
                $fee['excise'] ? ' (includes 20% excise duty)' : ''
            );

            $this->ledger->recordEntry($loan->id, self::LEDGER_ENTRY_TYPE, $fee['amount'], '0.00', $reference, $notes);

            if ($fee['excise']) {
                $this->gl->recordFeeWithExcisePosting($loan, $fee['name'], $fee['amount']);
            } else {
                $this->gl->postBalancedEntry(
                    description: sprintf('Fee [%s] on Loan #%s', $fee['name'], $loan->loan_number),
                    items: [
                        ['account_code' => '1200', 'debit' => $fee['amount'], 'credit' => '0.00'],
                        ['account_code' => '4020', 'debit' => '0.00', 'credit' => $fee['amount']],
                    ],
                    referenceType: Loan::class,
                    referenceId: $loan->id
                );
            }

            AuditTrail::create([
                'user_id'        => auth()->id(),
                'action'         => 'LOAN_FEE_CHARGED',
                'auditable_type' => Loan::class,
                'auditable_id'   => $loan->id,
                'old_values'     => null,
                'new_values'     => [
                    'fee'       => $fee['name'],
                    'amount'    => $fee['amount'],
                    'excise'    => $fee['excise'],
                    'reference' => $reference,
                ],
                'ip_address'     => request()->ip() ?? '127.0.0.1',
                'user_agent'     => request()->userAgent() ?? 'System/CLI',
            ]);

            $charged[] = $fee;
        }

        return $charged;
    }

    /** Total fees already added to this loan's balance. */
    public function chargedTotal(Loan $loan): string
    {
        $total = '0.00';

        $entries = LoanLedgerEntry::query()
            ->where('loan_id', $loan->id)
            ->where('entry_type', self::LEDGER_ENTRY_TYPE)
            ->get();

        foreach ($entries as $entry) {
            $total = bcadd($total, (string) $entry->debit, 2);
        }

        return $total;
    }

    /**
     * Refuses to proceed if the ledger accounts needed for this payout are missing.
     * Checked BEFORE money is sent, so a posting can never fail afterwards.
     */
    public function assertLedgerReady(Loan $loan): void
    {
        $needed = ['1010', '1200'];
        $quote = $this->quote($loan);

        if ($quote !== []) {
            $needed[] = '4020';

            foreach ($quote as $fee) {
                if ($fee['excise']) {
                    $needed[] = '2030';
                    break;
                }
            }
        }

        $present = ChartOfAccount::query()
            ->whereIn('account_code', $needed)
            ->pluck('account_code')
            ->map(fn ($code): string => (string) $code)
            ->all();

        $missing = array_values(array_diff($needed, $present));

        if ($missing !== []) {
            throw new RuntimeException(
                'The ledger is missing accounts (' . implode(', ', $missing) . '). Run the ChartOfAccountSeeder before sending money.'
            );
        }
    }

    /** Rounds half up to the cent, using BCMath strings only. */
    private function percentOf(string $principal, string $percentage): string
    {
        $raw = bcdiv(bcmul($principal, $percentage, 4), '100', 4);

        return bcadd($raw, '0.005', 2);
    }
}