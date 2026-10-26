<?php

declare(strict_types=1);

namespace App\Services\Servicing;

use App\Enums\LoanStatus;
use App\Models\AmortizationSchedule;
use App\Models\AuditTrail;
use App\Models\CollectionCase;
use App\Models\Loan;
use App\Models\LoanLedgerEntry;
use App\Services\Financial\InDuplumEnforcementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class OverdueDetectionService
{
    public const DEFAULT_DPD = 90;

    private const TZ = 'Africa/Nairobi';

    public function __construct(private readonly InDuplumEnforcementService $inDuplum)
    {
    }

    /**
     * Same bucket codes as FairCollectionsService and the workbench filter.
     */
    public static function bucketFor(int $daysPastDue): string
    {
        return match (true) {
            $daysPastDue <= 30 => 'DPD_1_30',
            $daysPastDue <= 60 => 'DPD_31_60',
            $daysPastDue <= 90 => 'DPD_61_90',
            default            => 'DPD_90_PLUS',
        };
    }

    /**
     * Days past due as of a Nairobi calendar day. 0 means not overdue.
     */
    public function daysPastDue(Loan $loan, ?CarbonImmutable $asOf = null): int
    {
        $today = ($asOf ?? CarbonImmutable::now())->setTimezone(self::TZ)->startOfDay();

        $latest = LoanLedgerEntry::query()->where('loan_id', $loan->id)->latest('id')->first();
        if ($latest === null || bccomp((string) $latest->total_running_balance, '0.00', 2) <= 0) {
            return 0;
        }

        $dueDay = $this->dueDay($loan);
        if ($dueDay === null) {
            return 0;
        }

        $due = CarbonImmutable::parse($dueDay, self::TZ)->startOfDay();

        return max(0, (int) $due->diffInDays($today, false));
    }

    /**
     * The Nairobi day the oldest unpaid amount fell due, or null when the loan is not overdue.
     */
    public function overdueSince(Loan $loan, ?CarbonImmutable $asOf = null): ?CarbonImmutable
    {
        if ($this->daysPastDue($loan, $asOf) < 1) {
            return null;
        }

        $dueDay = $this->dueDay($loan);

        return $dueDay === null ? null : CarbonImmutable::parse($dueDay, self::TZ)->startOfDay();
    }

    /**
     * @return array{dpd: int, case: string, defaulted: bool}
     */
    public function processLoan(Loan $loan, ?CarbonImmutable $asOf = null): array
    {
        $dpd = $this->daysPastDue($loan, $asOf);

        if ($dpd < 1) {
            return ['dpd' => 0, 'case' => 'none', 'defaulted' => false];
        }

        $bucket = self::bucketFor($dpd);
        $case = CollectionCase::query()->where('loan_id', $loan->id)->first();
        $result = 'unchanged';

        if ($case === null) {
            $case = CollectionCase::create([
                'loan_id'                => $loan->id,
                'borrower_id'            => $loan->borrower_id,
                'dpd_bucket'             => $bucket,
                'assigned_agent_user_id' => null,
                'case_status'            => 'open',
                'last_contacted_at'      => null,
            ]);
            $this->audit('COLLECTION_CASE_OPENED', $case->id, null, ['dpd_bucket' => $bucket, 'days_past_due' => $dpd]);
            $result = 'opened';
        } elseif ($case->dpd_bucket !== $bucket) {
            $old = $case->dpd_bucket;
            $case->update(['dpd_bucket' => $bucket]);
            $this->audit('COLLECTION_BUCKET_CHANGED', $case->id, ['dpd_bucket' => $old], ['dpd_bucket' => $bucket, 'days_past_due' => $dpd]);
            $result = 'updated';
        }

        $defaulted = false;
        if ($dpd >= self::DEFAULT_DPD && $loan->status !== LoanStatus::DEFAULTED) {
            $this->inDuplum->snapshotDefaultPrincipal($loan);
            $defaulted = true;
        }

        return ['dpd' => $dpd, 'case' => $result, 'defaulted' => $defaulted];
    }

    /**
     * @return array{checked: int, overdue: int, opened: int, updated: int, defaulted: int, failed: int}
     */
    public function detectAll(?CarbonImmutable $asOf = null): array
    {
        $stats = ['checked' => 0, 'overdue' => 0, 'opened' => 0, 'updated' => 0, 'defaulted' => 0, 'failed' => 0];

        Loan::query()
            ->whereIn('status', [
                LoanStatus::DISBURSED->value,
                LoanStatus::RESTRUCTURED->value,
                LoanStatus::DEFAULTED->value,
            ])
            ->chunkById(200, function ($loans) use (&$stats, $asOf): void {
                foreach ($loans as $loan) {
                    $stats['checked']++;

                    try {
                        $r = $this->processLoan($loan, $asOf);
                    } catch (Throwable $e) {
                        $stats['failed']++;
                        Log::channel('fintech')->error('Overdue detection failed for loan ' . $loan->id . ': ' . $e->getMessage());
                        continue;
                    }

                    if ($r['dpd'] > 0) {
                        $stats['overdue']++;
                    }
                    if ($r['case'] === 'opened') {
                        $stats['opened']++;
                    }
                    if ($r['case'] === 'updated') {
                        $stats['updated']++;
                    }
                    if ($r['defaulted']) {
                        $stats['defaulted']++;
                    }
                }
            });

        return $stats;
    }

    /**
     * Due day (Y-m-d) of the oldest unpaid instalment, or the loan's due date when there is no schedule.
     */
    private function dueDay(Loan $loan): ?string
    {
        $unpaid = AmortizationSchedule::query()
            ->where('loan_id', $loan->id)
            ->where('status', '!=', 'paid')
            ->orderBy('installment_number')
            ->first();

        if ($unpaid !== null) {
            return $unpaid->due_date->toDateString();
        }

        if ($loan->due_date !== null) {
            return $loan->due_date->copy()->setTimezone(self::TZ)->toDateString();
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed> $new
     */
    private function audit(string $action, int $caseId, ?array $old, array $new): void
    {
        AuditTrail::create([
            'user_id'        => auth()->id(),
            'action'         => $action,
            'auditable_type' => CollectionCase::class,
            'auditable_id'   => $caseId,
            'old_values'     => $old,
            'new_values'     => $new,
            'ip_address'     => request()->ip() ?? '127.0.0.1',
            'user_agent'     => request()->userAgent() ?? 'System/CLI',
        ]);
    }
}