<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\Enums\LoanStatus;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanLedgerEntry;
use App\Services\Servicing\RestructuringEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class BorrowerPortalService
{
    /** Extra days of time a borrower can choose from. */
    public const EXTENSION_OPTIONS = [14, 21, 30, 45, 60];

    public const DEFAULT_EXTENSION_DAYS = 30;

    /** Mirrors the threshold inside RestructuringEngine. The engine remains the final judge. */
    private const MINIMUM_ELIGIBILITY_RATIO = '0.30';

    public function __construct(private readonly RestructuringEngine $engine)
    {
    }

    /**
     * Everything the borrower dashboard needs.
     *
     * @return array<string, mixed>
     */
    public function summary(Borrower $borrower): array
    {
        $loan = $this->currentLoan($borrower);

        if ($loan === null) {
            return ['loan' => null];
        }

        $balances = $this->balances($loan);
        $owed = $this->owed($balances);
        $due = $loan->due_date !== null ? CarbonImmutable::parse($loan->due_date) : null;

        return [
            'loan'       => $loan,
            'balances'   => $balances,
            'owed'       => $owed,
            'due_date'   => $due?->format('j M Y'),
            'is_overdue' => $due !== null && $due->endOfDay()->isPast() && bccomp($owed, '0.00', 2) > 0,
            'meter'      => $this->inDuplumMeter($loan),
            'relief'     => $this->canRequestRelief($loan) ? $this->reliefPreview($borrower, $balances) : null,
        ];
    }

    /** The borrower's latest loan that is not settled or written off. */
    public function currentLoan(Borrower $borrower): ?Loan
    {
        /** @var Loan|null $loan */
        $loan = $borrower->loans()
            ->whereNotIn('status', [LoanStatus::SETTLED->value, LoanStatus::WRITTEN_OFF->value])
            ->with('inDuplumTracking')
            ->latest('id')
            ->first();

        return $loan;
    }

    /**
     * Latest balances from the append-only ledger.
     *
     * @return array{principal: string, interest: string, fees: string}
     */
    public function balances(Loan $loan): array
    {
        $latest = LoanLedgerEntry::query()->where('loan_id', $loan->id)->latest('id')->first();

        if ($latest === null) {
            return [
                'principal' => (string) $loan->principal_disbursed,
                'interest'  => '0.00',
                'fees'      => '0.00',
            ];
        }

        return [
            'principal' => (string) $latest->running_principal_balance,
            'interest'  => (string) $latest->running_interest_balance,
            'fees'      => (string) $latest->running_fees_balance,
        ];
    }

    /**
     * Data for the Section 44A safety meter.
     * States: not_triggered (no default yet), accruing (charges counting), frozen (cap reached).
     *
     * @return array{state: string, accrued: string, cap: string, percent: string, headroom: string}
     */
    public function inDuplumMeter(Loan $loan): array
    {
        $tracking = $loan->inDuplumTracking;

        if ($tracking === null) {
            return [
                'state'    => 'not_triggered',
                'accrued'  => '0.00',
                'cap'      => '0.00',
                'percent'  => '0.00',
                'headroom' => '0.00',
            ];
        }

        $accrued = bcadd(
            (string) $tracking->total_post_default_interest,
            (string) $tracking->total_post_default_penalties,
            2
        );
        $cap = (string) $tracking->statutory_cap_limit;

        $percent = '0.00';
        if (bccomp($cap, '0.00', 2) > 0) {
            $percent = bcmul(bcdiv($accrued, $cap, 4), '100', 2);
            if (bccomp($percent, '100.00', 2) > 0) {
                $percent = '100.00';
            }
        }

        $headroom = bcsub($cap, $accrued, 2);
        if (bccomp($headroom, '0.00', 2) < 0) {
            $headroom = '0.00';
        }

        $frozen = (bool) $tracking->is_frozen || (bool) $loan->is_in_duplum_locked;

        return [
            'state'    => $frozen ? 'frozen' : 'accruing',
            'accrued'  => $accrued,
            'cap'      => $cap,
            'percent'  => $percent,
            'headroom' => $headroom,
        ];
    }

    /** Relief is offered on defaulted loans, or disbursed loans past their due date. */
    public function canRequestRelief(Loan $loan): bool
    {
        if ($loan->status === LoanStatus::DEFAULTED) {
            return true;
        }

        return $loan->status === LoanStatus::DISBURSED
            && $loan->due_date !== null
            && CarbonImmutable::parse($loan->due_date)->endOfDay()->isPast();
    }

    /**
     * A read-only preview that follows the same rules as RestructuringEngine
     * (penalties forgiven, interest kept, balance split into two instalments).
     * Nothing is saved here.
     *
     * @param  array{principal: string, interest: string, fees: string}  $balances
     * @return array<string, mixed>
     */
    public function reliefPreview(Borrower $borrower, array $balances): array
    {
        $ratio = $this->engine->calculateRepaymentRatio($borrower);

        $owedToday = $this->owed($balances);
        $owedAfter = bcadd($balances['principal'], $balances['interest'], 2);

        $first = bcdiv($owedAfter, '2.00', 2);
        $remainder = bcsub($owedAfter, bcmul($first, '2.00', 2), 2);
        $second = bcadd($first, $remainder, 2);

        $today = CarbonImmutable::now();
        $schedule = [];
        foreach (self::EXTENSION_OPTIONS as $days) {
            $schedule[$days] = [
                'first_due'  => $today->addDays(intdiv($days, 2))->format('j M Y'),
                'second_due' => $today->addDays($days)->format('j M Y'),
            ];
        }

        return [
            'eligible'     => bccomp($ratio, self::MINIMUM_ELIGIBILITY_RATIO, 2) >= 0,
            'owed_today'   => $owedToday,
            'waived'       => $balances['fees'],
            'owed_after'   => $owedAfter,
            'first_amount' => $first,
            'second_amount' => $second,
            'options'      => self::EXTENSION_OPTIONS,
            'default_days' => self::DEFAULT_EXTENSION_DAYS,
            'schedule'     => $schedule,
        ];
    }

    /**
     * Creates and accepts a restructuring proposal in one transaction,
     * using the same engine the lender side relies on.
     */
    public function acceptRelief(Loan $loan, int $extensionDays): Loan
    {
        if (! in_array($extensionDays, self::EXTENSION_OPTIONS, true)) {
            throw new \InvalidArgumentException('Unsupported extension period.');
        }

        if (! $this->canRequestRelief($loan)) {
            throw new \DomainException('This loan is not in arrears, so relief cannot be requested.');
        }

        return DB::transaction(function () use ($loan, $extensionDays): Loan {
            $proposal = $this->engine->generateProposal($loan, $extensionDays);

            return $this->engine->acceptProposal($proposal);
        });
    }

    /** @param array{principal: string, interest: string, fees: string} $balances */
    private function owed(array $balances): string
    {
        return bcadd(bcadd($balances['principal'], $balances['interest'], 2), $balances['fees'], 2);
    }
}