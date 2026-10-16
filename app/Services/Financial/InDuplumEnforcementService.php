<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Enums\LoanStatus;
use App\Exceptions\Fintech\InDuplumCapExceededException;
use App\Models\AuditTrail;
use App\Models\InDuplumTracking;
use App\Models\Loan;
use App\Models\OutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class InDuplumEnforcementService
{
    /**
     * Captures an immutable snapshot of principal when a loan enters default (90 DPD).
     */
    public function snapshotDefaultPrincipal(Loan $loan): InDuplumTracking
    {
        return DB::transaction(function () use ($loan): InDuplumTracking {
            $lockedLoan = Loan::query()->where('id', $loan->id)->lockForUpdate()->firstOrFail();

            $existing = InDuplumTracking::query()->where('loan_id', $lockedLoan->id)->first();
            if ($existing !== null) {
                return $existing;
            }

            // In-Duplum cap equals principal balance outstanding at default
            $snapshotPrincipal = (string) $lockedLoan->principal_disbursed;
            $now = CarbonImmutable::now();

            $tracking = InDuplumTracking::create([
                'loan_id'                      => $lockedLoan->id,
                'defaulted_at'                 => $lockedLoan->defaulted_at ?? $now,
                'default_principal_snapshot'   => $snapshotPrincipal,
                'total_post_default_interest'  => '0.00',
                'total_post_default_penalties' => '0.00',
                'statutory_cap_limit'          => $snapshotPrincipal,
                'is_frozen'                    => false,
                'frozen_at'                    => null,
            ]);

            $lockedLoan->update([
                'status'       => LoanStatus::DEFAULTED,
                'defaulted_at' => $lockedLoan->defaulted_at ?? $now,
            ]);

            AuditTrail::create([
                'user_id'        => auth()->id(),
                'action'         => 'IN_DUPLUM_DEFAULT_SNAPSHOT',
                'auditable_type' => Loan::class,
                'auditable_id'   => $lockedLoan->id,
                'old_values'     => null,
                'new_values'     => [
                    'snapshot_principal' => $snapshotPrincipal,
                    'statutory_cap'      => $snapshotPrincipal,
                ],
                'ip_address'     => request()->ip() ?? '127.0.0.1',
                'user_agent'     => request()->userAgent() ?? 'System/CLI',
            ]);

            return $tracking;
        });
    }

    /**
     * Calculates the remaining allowable post-default accrual headroom using BCMath.
     * Headroom = default_principal_snapshot - (total_post_default_interest + total_post_default_penalties)
     */
    public function getRemainingCapHeadroom(Loan $loan): string
    {
        $tracking = InDuplumTracking::query()->where('loan_id', $loan->id)->first();

        if ($tracking === null) {
            // Not in default; In-Duplum ceiling applies only once non-performing/defaulted
            return '999999999.99';
        }

        $snapshot = (string) $tracking->default_principal_snapshot;
        $accruedInterest = (string) $tracking->total_post_default_interest;
        $accruedPenalties = (string) $tracking->total_post_default_penalties;

        $totalAccrued = bcadd($accruedInterest, $accruedPenalties, 2);
        $headroom = bcsub($snapshot, $totalAccrued, 2);

        return bccomp($headroom, '0.00', 2) < 0 ? '0.00' : $headroom;
    }

    /**
     * Asserts whether a charge can be legally accrued under Section 44A.
     * Throws an exception or freezes the account if the cap is breached.
     *
     * @throws InDuplumCapExceededException
     */
    public function assertCanAccrue(Loan $loan, string $amount, string $entryType): bool
    {
        $tracking = InDuplumTracking::query()->where('loan_id', $loan->id)->first();

        if ($tracking === null) {
            return true;
        }

        if ($tracking->is_frozen || $loan->is_in_duplum_locked) {
            throw new InDuplumCapExceededException(
                loanId: $loan->id,
                defaultPrincipalSnapshot: (string) $tracking->default_principal_snapshot,
                accumulatedPostDefaultCharges: bcadd((string) $tracking->total_post_default_interest, (string) $tracking->total_post_default_penalties, 2),
                attemptedCharge: $amount,
                message: "Loan #{$loan->id} is frozen under Section 44A In-Duplum. Zero further charges permitted."
            );
        }

        $headroom = $this->getRemainingCapHeadroom($loan);

        if (bccomp($amount, $headroom, 2) > 0) {
            throw new InDuplumCapExceededException(
                loanId: $loan->id,
                defaultPrincipalSnapshot: (string) $tracking->default_principal_snapshot,
                accumulatedPostDefaultCharges: bcadd((string) $tracking->total_post_default_interest, (string) $tracking->total_post_default_penalties, 2),
                attemptedCharge: $amount
            );
        }

        return true;
    }

    /**
     * Records a post-default accrual and automatically freezes the facility if the cap is reached.
     */
    public function recordPostDefaultAccrual(Loan $loan, string $amount, string $category = 'interest'): InDuplumTracking
    {
        return DB::transaction(function () use ($loan, $amount, $category): InDuplumTracking {
            $lockedLoan = Loan::query()->where('id', $loan->id)->lockForUpdate()->firstOrFail();
            $tracking = InDuplumTracking::query()->where('loan_id', $lockedLoan->id)->lockForUpdate()->firstOrFail();

            $this->assertCanAccrue($lockedLoan, $amount, $category);

            if ($category === 'interest') {
                $tracking->total_post_default_interest = bcadd((string) $tracking->total_post_default_interest, $amount, 2);
            } else {
                $tracking->total_post_default_penalties = bcadd((string) $tracking->total_post_default_penalties, $amount, 2);
            }

            $tracking->save();

            // Evaluate if this accrual exactly hits or exhausts the statutory cap
            $remaining = $this->getRemainingCapHeadroom($lockedLoan);
            if (bccomp($remaining, '0.00', 2) === 0) {
                $this->enforceStatutoryFreeze($lockedLoan, 'Post-default accruals reached exact statutory In-Duplum ceiling.');
                $tracking->refresh();
            }

            return $tracking;
        });
    }

    /**
     * Enforces the statutory freeze: locks the loan, sets flags, and writes an Outbox event.
     */
    public function enforceStatutoryFreeze(Loan $loan, string $reason): void
    {
        DB::transaction(function () use ($loan, $reason): void {
            $lockedLoan = Loan::query()->where('id', $loan->id)->lockForUpdate()->firstOrFail();
            $tracking = InDuplumTracking::query()->where('loan_id', $lockedLoan->id)->lockForUpdate()->first();

            $now = CarbonImmutable::now();

            $lockedLoan->update([
                'is_in_duplum_locked' => true,
            ]);

            if ($tracking !== null) {
                $tracking->update([
                    'is_frozen' => true,
                    'frozen_at' => $now,
                ]);
            }

            // Cryptographically chained Outbox Event
            $prevEvent = OutboxEvent::query()->latest('id')->first();
            $prevHash = $prevEvent ? $prevEvent->current_event_hash : str_repeat('0', 64);

            $payload = [
                'loan_id'          => $lockedLoan->id,
                'loan_number'      => $lockedLoan->loan_number,
                'borrower_id'      => $lockedLoan->borrower_id,
                'statutory_freeze' => true,
                'cap_ceiling'      => $tracking ? (string) $tracking->statutory_cap_limit : (string) $lockedLoan->principal_disbursed,
                'reason'           => $reason,
                'frozen_at'        => $now->toIso8601String(),
            ];

            $currentHash = hash('sha256', $prevHash . 'loan.in_duplum_frozen' . json_encode($payload));

            OutboxEvent::create([
                'aggregate_type'      => Loan::class,
                'aggregate_id'        => $lockedLoan->id,
                'event_name'          => 'loan.in_duplum_frozen',
                'payload'             => $payload,
                'previous_event_hash' => $prevHash,
                'current_event_hash'  => $currentHash,
                'is_published'        => false,
                'retry_count'         => 0,
            ]);

            AuditTrail::create([
                'user_id'        => auth()->id(),
                'action'         => 'IN_DUPLUM_STATUTORY_FREEZE',
                'auditable_type' => Loan::class,
                'auditable_id'   => $lockedLoan->id,
                'old_values'     => ['is_in_duplum_locked' => false],
                'new_values'     => ['is_in_duplum_locked' => true, 'reason' => $reason],
                'ip_address'     => request()->ip() ?? '127.0.0.1',
                'user_agent'     => request()->userAgent() ?? 'System/CLI',
            ]);

            Log::channel('daily')->warning(sprintf(
                'IN-DUPLUM STATUTORY FREEZE: Loan #%d has reached its legal ceiling under Banking Act Section 44A. All accruals halted.',
                $lockedLoan->id
            ));
        });
    }

    /**
     * Batch scanner for scheduled daemon runs. Evaluates all defaulted, non-frozen loans.
     */
    public function auditAndFreezeBreachedPortfolio(): int
    {
        $frozenCount = 0;

        $unfrozenTrackings = InDuplumTracking::query()
            ->with('loan')
            ->where('is_frozen', false)
            ->get();

        foreach ($unfrozenTrackings as $tracking) {
            $totalAccrued = bcadd(
                (string) $tracking->total_post_default_interest,
                (string) $tracking->total_post_default_penalties,
                2
            );

            if (bccomp($totalAccrued, (string) $tracking->default_principal_snapshot, 2) >= 0) {
                $this->enforceStatutoryFreeze(
                    $tracking->loan,
                    'Batch audit discovered post-default accruals met or exceeded snapshot principal.'
                );
                $frozenCount++;
            }
        }

        return $frozenCount;
    }
}