<?php

declare(strict_types=1);

namespace App\Services\Servicing;

use App\Enums\LoanStatus;
use App\Exceptions\Fintech\RegulatoryCooldownActiveException;
use App\Exceptions\Fintech\OutsideLegalOutreachHoursException;
use App\Models\AuditTrail;
use App\Models\Borrower;
use App\Models\BorrowerOptOut;
use App\Models\CollectionAction;
use App\Models\CollectionCase;
use App\Models\Loan;
use App\Models\OutboxEvent;
use App\Models\PromiseToPay;
use App\Models\RegulatoryCooldownLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class FairCollectionsService
{
    public const LEGAL_HOUR_START = 8;  // 08:00 AM EAT
    public const LEGAL_HOUR_END   = 18; // 06:00 PM EAT
    public const COOLDOWN_HOURS   = 48; // 48-Hour quiet period

    /**
     * Asserts whether the current time falls inside the statutory contact window (08:00–18:00 EAT).
     */
    public function isWithinLegalContactHours(?CarbonImmutable $time = null): bool
    {
        $eatTime = ($time ?? CarbonImmutable::now())->setTimezone('Africa/Nairobi');
        $hour = (int) $eatTime->format('H');

        return $hour >= self::LEGAL_HOUR_START && $hour < self::LEGAL_HOUR_END;
    }
    
/**
 * Asserts legal hours, throwing an explicit regulatory exception if outside 08:00–18:00 EAT.
 *
 * @throws OutsideLegalOutreachHoursException
 */
public function assertWithinLegalContactHours(?CarbonImmutable $time = null): bool
{
    $eatTime = ($time ?? CarbonImmutable::now())->setTimezone('Africa/Nairobi');

    if (!$this->isWithinLegalContactHours($eatTime)) {
        throw new OutsideLegalOutreachHoursException(
            currentTimeEAT: $eatTime->format('H:i')
        );
    }

    return true;
}

    /**
     * Checks if a borrower is currently locked under the 48-hour quiet period.
     */
    public function hasActiveCooldown(Borrower $borrower, ?CarbonImmutable $time = null): bool
    {
        $now = $time ?? CarbonImmutable::now();

        return RegulatoryCooldownLock::query()
            ->where('borrower_id', $borrower->id)
            ->where('lock_type', 'COLLECTIONS_SMS')
            ->where('locked_until', '>', $now)
            ->exists();
    }

    /**
     * Checks if the collection case has an active, unexpired Promise to Pay (PTP).
     */
    public function hasActivePromiseToPay(CollectionCase $case, ?CarbonImmutable $time = null): bool
    {
        $now = ($time ?? CarbonImmutable::now())->toDateString();

        return PromiseToPay::query()
            ->where('collection_case_id', $case->id)
            ->where('status', 'pending')
            ->where('promised_date', '>=', $now)
            ->exists();
    }

    /**
     * Evaluates a delinquent loan and dispatches a compliant reminder if legal.
     */
    public function processCollectionEscalation(Loan $loan, ?CarbonImmutable $currentTime = null): ?CollectionAction
    {
        $now = $currentTime ?? CarbonImmutable::now();

        // 1. Guard against illegal communication hours (08:00 - 18:00 EAT only)
        if (!$this->isWithinLegalContactHours($now)) {
            Log::channel('daily')->info(sprintf(
                'Fair Collections: Skipped Loan #%d outside legal hours (%s EAT)',
                $loan->id,
                $now->setTimezone('Africa/Nairobi')->format('H:i')
            ));
            return null;
        }

        return DB::transaction(function () use ($loan, $now): ?CollectionAction {
            $lockedLoan = Loan::query()->where('id', $loan->id)->lockForUpdate()->firstOrFail();
            $borrower = $lockedLoan->borrower;

            // 2. Check if borrower opted out of non-mandatory communications
            $optedOut = BorrowerOptOut::query()
                ->where('borrower_id', $borrower->id)
                ->where('channel', 'SMS')
                ->exists();

            if ($optedOut) {
                return null;
            }

            // 3. Resolve or create Collection Case
            $case = CollectionCase::query()
                ->where('loan_id', $lockedLoan->id)
                ->first();

            if ($case === null) {
                $daysOverdue = max(1, (int) CarbonImmutable::parse($lockedLoan->due_date)->diffInDays($now));
                $bucket = $this->resolveDpdBucket($daysOverdue);

                $case = CollectionCase::create([
                    'loan_id'                => $lockedLoan->id,
                    'borrower_id'            => $borrower->id,
                    'dpd_bucket'             => $bucket,
                    'assigned_agent_user_id' => null,
                    'case_status'            => 'open',
                    'last_contacted_at'      => null,
                ]);
            }

            // 4. Guard against active Promise-to-Pay (PTP) commitments
            if ($this->hasActivePromiseToPay($case, $now)) {
                Log::channel('daily')->info(sprintf('Fair Collections: Loan #%d paused on active Promise-to-Pay', $loan->id));
                return null;
            }

            // 5. Guard against 48-Hour Cooldown lock
            $activeLock = RegulatoryCooldownLock::query()
                ->where('borrower_id', $borrower->id)
                ->where('lock_type', 'COLLECTIONS_SMS')
                ->where('locked_until', '>', $now)
                ->first();

            if ($activeLock !== null) {
                $hoursRemaining = (int) $now->diffInHours($activeLock->locked_until);
                throw new RegulatoryCooldownActiveException(
                    borrowerId: $borrower->id,
                    lockedUntil: $activeLock->locked_until->toIso8601String(),
                    hoursRemaining: max(1, $hoursRemaining)
                );
            }

            // 6. Generate ethical, non-shaming SMS message payload
            $daysOverdue = max(1, (int) CarbonImmutable::parse($lockedLoan->due_date)->diffInDays($now));
            $bucket = $this->resolveDpdBucket($daysOverdue);
            $message = $this->craftEthicalMessage($lockedLoan, $daysOverdue);

            // 7. Record Collection Action
            $action = CollectionAction::create([
                'collection_case_id'   => $case->id,
                'performed_by_user_id' => null, // Automated system
                'action_type'          => 'SMS',
                'message_payload'      => $message,
                'is_compliant_hours'   => true,
                'gateway_status'       => 'queued',
            ]);

            // 8. Apply atomic 48-Hour Cooldown Lock
            $lockExpiry = $now->addHours(self::COOLDOWN_HOURS);
            RegulatoryCooldownLock::create([
                'borrower_id'  => $borrower->id,
                'lock_type'    => 'COLLECTIONS_SMS',
                'locked_until' => $lockExpiry,
                'reason'       => sprintf('48-hour quiet period following %s reminder on Loan #%s', $bucket, $lockedLoan->loan_number),
            ]);

            $case->update([
                'last_contacted_at' => $now,
                'dpd_bucket'        => $bucket,
            ]);

            // 9. Dispatch Outbox Event for Africa's Talking SMS delivery
            $prevEvent = OutboxEvent::query()->latest('id')->first();
            $prevHash = $prevEvent ? $prevEvent->current_event_hash : str_repeat('0', 64);

            $payload = [
                'action_id'     => $action->id,
                'borrower_id'   => $borrower->id,
                'phone_number'  => $borrower->phone_number,
                'message'       => $message,
                'dispatched_at' => $now->toIso8601String(),
            ];

            $currentHash = hash('sha256', $prevHash . 'collection.sms_queued' . json_encode($payload));

            OutboxEvent::create([
                'aggregate_type'      => CollectionAction::class,
                'aggregate_id'        => $action->id,
                'event_name'          => 'collection.sms_queued',
                'payload'             => $payload,
                'previous_event_hash' => $prevHash,
                'current_event_hash'  => $currentHash,
                'is_published'        => false,
                'retry_count'         => 0,
            ]);

            AuditTrail::create([
                'user_id'        => auth()->id(),
                'action'         => 'COLLECTION_ESCALATION_DISPATCHED',
                'auditable_type' => CollectionAction::class,
                'auditable_id'   => $action->id,
                'old_values'     => null,
                'new_values'     => [
                    'bucket'       => $bucket,
                    'locked_until' => $lockExpiry->toIso8601String(),
                ],
                'ip_address'     => request()->ip() ?? '127.0.0.1',
                'user_agent'     => request()->userAgent() ?? 'System/CLI',
            ]);

            return $action;
        });
    }

    /**
     * Batch auditor running daily at 08:30 AM EAT.
     */
    public function scanAndEscalateDelinquentPortfolio(?CarbonImmutable $currentTime = null): array
    {
        $now = $currentTime ?? CarbonImmutable::now();
        $dispatched = 0;
        $skippedHours = 0;
        $skippedCooldown = 0;

        if (!$this->isWithinLegalContactHours($now)) {
            return [
                'status'           => 'HALTED_OUTSIDE_LEGAL_HOURS',
                'dispatched'       => 0,
                'skipped_cooldown' => 0,
            ];
        }

        // Find overdue active loans
        $overdueLoans = Loan::query()
            ->with(['borrower'])
            ->whereIn('status', [LoanStatus::DISBURSED->value, LoanStatus::DEFAULTED->value, 'watch'])
            ->where('due_date', '<', $now)
            ->get();

        foreach ($overdueLoans as $loan) {
            try {
                $action = $this->processCollectionEscalation($loan, $now);
                if ($action !== null) {
                    $dispatched++;
                }
            } catch (RegulatoryCooldownActiveException) {
                $skippedCooldown++;
            }
        }

        return [
            'status'           => 'COMPLETED',
            'dispatched'       => $dispatched,
            'skipped_cooldown' => $skippedCooldown,
        ];
    }

    private function resolveDpdBucket(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 30 => 'DPD_1_30',
            $daysOverdue <= 60 => 'DPD_31_60',
            $daysOverdue <= 90 => 'DPD_61_90',
            default            => 'DPD_90_PLUS',
        };
    }

    private function craftEthicalMessage(Loan $loan, int $daysOverdue): string
    {
        $firstName = explode(' ', (string) $loan->borrower->full_name)[0] ?? 'Customer';

        return sprintf(
            'Dear %s, your loan #%s is %d day(s) past due. If you are experiencing hardship, flexible restructuring options are available. Repay via Paybill 174379, Acc: %s. Call 0700000000 for assistance.',
            $firstName,
            $loan->loan_number,
            $daysOverdue,
            $loan->loan_number
        );
    }
}