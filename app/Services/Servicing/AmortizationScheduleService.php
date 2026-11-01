<?php

declare(strict_types=1);

namespace App\Services\Servicing;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Services\Lending\LoanFeeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * 14.1. Repayment schedule: one instalment per 30 days of tenure (rounded up).
 * Interest uses the same simple formula as the contract. Fees are what the ledger really charged.
 * 14.5a. When relief is accepted the unpaid instalments are superseded by the relief plan.
 */
final class AmortizationScheduleService
{
    public const DAYS_PER_INSTALMENT = 30;

    public const SUPERSEDED = 'superseded';

    public function __construct(private readonly LoanFeeService $fees)
    {
    }

    /**
     * Creates the schedule once. If rows already exist they are returned untouched.
     *
     * @return Collection<int, AmortizationSchedule>
     */
    public function generate(Loan $loan): Collection
    {
        return DB::transaction(function () use ($loan): Collection {
            $locked = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            $existing = AmortizationSchedule::query()
                ->where('loan_id', $locked->id)
                ->orderBy('installment_number')
                ->get();

            if ($existing->isNotEmpty()) {
                return $existing;
            }

            if ($locked->disbursed_at === null) {
                throw new RuntimeException('A schedule can only be made after the money is sent.');
            }

            $tenure = max(1, (int) $locked->tenure_days);
            $count = max(1, (int) ceil($tenure / self::DAYS_PER_INSTALMENT));
            $principal = (string) $locked->principal_disbursed;
            $interest = $this->interestFor($principal, (string) $locked->annual_interest_rate, $tenure);
            $fees = $this->fees->chargedTotal($locked);

            $principalParts = $this->split($principal, $count);
            $interestParts = $this->split($interest, $count);
            $start = CarbonImmutable::parse($locked->disbursed_at);

            for ($i = 1; $i <= $count; $i++) {
                $days = min($i * self::DAYS_PER_INSTALMENT, $tenure);
                $feesDue = $i === 1 ? $fees : '0.00';
                $p = $principalParts[$i - 1];
                $n = $interestParts[$i - 1];

                AmortizationSchedule::create([
                    'loan_id'               => $locked->id,
                    'installment_number'    => $i,
                    'due_date'              => $start->addDays($days)->toDateString(),
                    'principal_due'         => $p,
                    'interest_due'          => $n,
                    'fees_due'              => $feesDue,
                    'total_installment_due' => bcadd(bcadd($p, $n, 2), $feesDue, 2),
                    'principal_paid'        => '0.00',
                    'interest_paid'         => '0.00',
                    'fees_paid'             => '0.00',
                    'status'                => 'pending',
                ]);
            }

            return AmortizationSchedule::query()
                ->where('loan_id', $locked->id)
                ->orderBy('installment_number')
                ->get();
        });
    }

    /**
     * 14.5a. Relief accepted: unpaid instalments are marked superseded (kept for history) and new
     * instalments are written for the relief plan from the balances left after the waiver.
     * Paid instalments are untouched. Returns the last new due date (Y-m-d), or null when nothing was written.
     *
     * @param list<string> $dueDates
     */
    public function supersedeForRelief(Loan $loan, array $dueDates, string $fees, string $interest, string $principal): ?string
    {
        $dueDates = array_values($dueDates);
        $count = count($dueDates);
        $total = bcadd(bcadd($fees, $interest, 2), $principal, 2);

        if ($count === 0 || bccomp($total, '0.00', 2) <= 0) {
            return null;
        }

        return DB::transaction(function () use ($loan, $dueDates, $count, $total, $fees, $interest, $principal): string {
            AmortizationSchedule::query()
                ->where('loan_id', $loan->id)
                ->whereIn('status', ['pending', 'partial'])
                ->update(['status' => self::SUPERSEDED]);

            $number = (int) AmortizationSchedule::query()->where('loan_id', $loan->id)->max('installment_number');

            $each = bcdiv($total, (string) $count, 2);
            $remainingTotal = $total;
            $remFees = $fees;
            $remInterest = $interest;
            $remPrincipal = $principal;
            $lastDate = $dueDates[$count - 1];

            foreach ($dueDates as $index => $date) {
                $amount = $index === $count - 1 ? $remainingTotal : $each;
                $room = $amount;

                $f = bccomp($remFees, $room, 2) < 0 ? $remFees : $room;
                $room = bcsub($room, $f, 2);
                $n = bccomp($remInterest, $room, 2) < 0 ? $remInterest : $room;
                $room = bcsub($room, $n, 2);
                $p = $room;

                $remFees = bcsub($remFees, $f, 2);
                $remInterest = bcsub($remInterest, $n, 2);
                $remPrincipal = bcsub($remPrincipal, $p, 2);
                $remainingTotal = bcsub($remainingTotal, $amount, 2);

                $number++;

                AmortizationSchedule::create([
                    'loan_id'               => $loan->id,
                    'installment_number'    => $number,
                    'due_date'              => $date,
                    'principal_due'         => $p,
                    'interest_due'          => $n,
                    'fees_due'              => $f,
                    'total_installment_due' => $amount,
                    'principal_paid'        => '0.00',
                    'interest_paid'         => '0.00',
                    'fees_paid'             => '0.00',
                    'status'                => 'pending',
                ]);
            }

            return $lastDate;
        });
    }

    /**
     * Marks a repayment against the oldest unpaid instalments (fees, interest, principal).
     * Superseded instalments are skipped.
     * Bookkeeping only: it never throws, so it can never undo a repayment.
     */
    public function applyPayment(int $loanId, string $fees, string $interest, string $principal): void
    {
        try {
            DB::transaction(function () use ($loanId, $fees, $interest, $principal): void {
                $rows = AmortizationSchedule::query()
                    ->where('loan_id', $loanId)
                    ->where('status', '!=', self::SUPERSEDED)
                    ->orderBy('installment_number')
                    ->lockForUpdate()
                    ->get();

                if ($rows->isEmpty()) {
                    return;
                }

                $remainingFees = $fees;
                $remainingInterest = $interest;
                $remainingPrincipal = $principal;

                foreach ($rows as $row) {
                    [$f, $remainingFees] = $this->take($remainingFees, (string) $row->fees_due, (string) $row->fees_paid);
                    [$n, $remainingInterest] = $this->take($remainingInterest, (string) $row->interest_due, (string) $row->interest_paid);
                    [$p, $remainingPrincipal] = $this->take($remainingPrincipal, (string) $row->principal_due, (string) $row->principal_paid);

                    $feesPaid = bcadd((string) $row->fees_paid, $f, 2);
                    $interestPaid = bcadd((string) $row->interest_paid, $n, 2);
                    $principalPaid = bcadd((string) $row->principal_paid, $p, 2);
                    $paidTotal = bcadd(bcadd($feesPaid, $interestPaid, 2), $principalPaid, 2);

                    $status = 'pending';
                    if (bccomp($paidTotal, (string) $row->total_installment_due, 2) >= 0) {
                        $status = 'paid';
                    } elseif (bccomp($paidTotal, '0.00', 2) > 0) {
                        $status = 'partial';
                    }

                    $row->update([
                        'fees_paid'      => $feesPaid,
                        'interest_paid'  => $interestPaid,
                        'principal_paid' => $principalPaid,
                        'status'         => $status,
                    ]);
                }
            });
        } catch (Throwable $e) {
            Log::channel('fintech')->warning('Schedule update failed for loan ' . $loanId . ': ' . $e->getMessage());
        }
    }

    /** principal x rate x days / 36,500, divided last, rounded half up to the cent. */
    private function interestFor(string $principal, string $annualRate, int $days): string
    {
        $raw = bcdiv(bcmul(bcmul($principal, $annualRate, 4), (string) $days, 4), '36500', 4);

        return bcadd($raw, '0.005', 2);
    }

    /**
     * Equal parts to the cent; the last part takes the rounding remainder.
     *
     * @return list<string>
     */
    private function split(string $amount, int $count): array
    {
        $each = bcdiv($amount, (string) $count, 2);
        $parts = array_fill(0, $count, $each);
        $parts[$count - 1] = bcsub($amount, bcmul($each, (string) ($count - 1), 2), 2);

        return $parts;
    }

    /**
     * How much of $remaining fits in what is still unpaid on this instalment.
     *
     * @return array{0: string, 1: string} [taken, remaining after]
     */
    private function take(string $remaining, string $due, string $paid): array
    {
        $room = bcsub($due, $paid, 2);

        if (bccomp($room, '0.00', 2) <= 0 || bccomp($remaining, '0.00', 2) <= 0) {
            return ['0.00', $remaining];
        }

        $used = bccomp($remaining, $room, 2) < 0 ? $remaining : $room;

        return [$used, bcsub($remaining, $used, 2)];
    }
}