<?php

declare(strict_types=1);

namespace App\Services\Servicing;

use App\Enums\LoanStatus;
use App\Models\InDuplumTracking;
use App\Models\Loan;
use App\Models\LoanLedgerEntry;
use App\Services\Financial\InDuplumEnforcementService;
use App\Services\Financial\LoanLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 14.2. Interest accrual by catch-up: interest owed up to today, minus what the ledger already holds.
 * Same simple formula as the contract and the schedule: principal x rate x days / 36,500.
 */
final class InterestAccrualService
{
    public const ENTRY_TYPE = 'INTEREST_ACCRUAL';

    private const TIMEZONE = 'Africa/Nairobi';

    public function __construct(
        private readonly LoanLedgerService $ledger,
        private readonly InDuplumEnforcementService $inDuplum
    ) {
    }

    /** Returns the amount posted ('0.00' when nothing was due). */
    public function accrueLoan(Loan $loan, ?CarbonImmutable $asOf = null): string
    {
        return DB::transaction(function () use ($loan, $asOf): string {
            $locked = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            $accruing = [LoanStatus::DISBURSED, LoanStatus::RESTRUCTURED, LoanStatus::DEFAULTED];

            if (! in_array($locked->status, $accruing, true) || $locked->is_in_duplum_locked || $locked->disbursed_at === null) {
                return '0.00';
            }

            if (! LoanLedgerEntry::query()->where('loan_id', $locked->id)->exists()) {
                return '0.00';
            }

            $day = ($asOf ?? CarbonImmutable::now())->setTimezone(self::TIMEZONE)->startOfDay();
            $days = $this->daysElapsed($locked->disbursed_at, $day);
            $tenure = max(1, (int) $locked->tenure_days);
            $defaulted = $locked->status === LoanStatus::DEFAULTED;

            // Before default, interest never goes past the contract total.
            $chargeDays = $defaulted ? $days : min($days, $tenure);

            $target = $this->interestFor((string) $locked->principal_disbursed, (string) $locked->annual_interest_rate, $chargeDays);
            $due = bcsub($target, $this->accruedTotal($locked->id), 2);

            if (bccomp($due, '0.00', 2) <= 0) {
                return '0.00';
            }

            if ($defaulted) {
                if (InDuplumTracking::query()->where('loan_id', $locked->id)->doesntExist()) {
                    return '0.00';
                }

                $headroom = $this->inDuplum->getRemainingCapHeadroom($locked);

                if (bccomp($due, $headroom, 2) > 0) {
                    $due = $headroom;
                }

                if (bccomp($due, '0.00', 2) <= 0) {
                    return '0.00';
                }

                // Section 44A: counts against the cap, and freezes the loan if the cap is reached.
                $this->inDuplum->recordPostDefaultAccrual($locked, $due, 'interest');
            }

            $this->ledger->recordEntry(
                $locked->id,
                self::ENTRY_TYPE,
                $due,
                '0.00',
                'ACCR-' . $locked->loan_number . '-' . $day->format('Ymd'),
                sprintf('Interest to day %d: KES %s', $chargeDays, $due)
            );

            return $due;
        });
    }

    /**
     * Runs every accruing loan. One bad loan never stops the rest.
     *
     * @return array{checked: int, posted: int, total: string, failed: int}
     */
    public function accrueAll(?CarbonImmutable $asOf = null): array
    {
        $result = ['checked' => 0, 'posted' => 0, 'total' => '0.00', 'failed' => 0];

        Loan::query()
            ->whereIn('status', [LoanStatus::DISBURSED->value, LoanStatus::RESTRUCTURED->value, LoanStatus::DEFAULTED->value])
            ->where('is_in_duplum_locked', false)
            ->whereNotNull('disbursed_at')
            ->orderBy('id')
            ->chunkById(100, function ($loans) use (&$result, $asOf): void {
                foreach ($loans as $loan) {
                    $result['checked']++;

                    try {
                        $amount = $this->accrueLoan($loan, $asOf);

                        if (bccomp($amount, '0.00', 2) > 0) {
                            $result['posted']++;
                            $result['total'] = bcadd($result['total'], $amount, 2);
                        }
                    } catch (Throwable $e) {
                        $result['failed']++;
                        Log::channel('fintech')->warning('Interest accrual failed for loan ' . $loan->loan_number . ': ' . $e->getMessage());
                    }
                }
            });

        return $result;
    }

    private function daysElapsed(mixed $disbursedAt, CarbonImmutable $today): int
    {
        $start = CarbonImmutable::parse($disbursedAt)->setTimezone(self::TIMEZONE)->startOfDay();

        return max(0, (int) $start->diffInDays($today));
    }

    private function accruedTotal(int $loanId): string
    {
        $total = '0.00';

        $rows = LoanLedgerEntry::query()
            ->where('loan_id', $loanId)
            ->where('entry_type', self::ENTRY_TYPE)
            ->get();

        foreach ($rows as $row) {
            $total = bcadd($total, (string) $row->debit, 2);
        }

        return $total;
    }

    /** Same formula as the contract and the schedule: divided last, rounded half up to the cent. */
    private function interestFor(string $principal, string $annualRate, int $days): string
    {
        $raw = bcdiv(bcmul(bcmul($principal, $annualRate, 4), (string) $days, 4), '36500', 4);

        return bcadd($raw, '0.005', 2);
    }
}