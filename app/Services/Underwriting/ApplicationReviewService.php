<?php

declare(strict_types=1);

namespace App\Services\Underwriting;

use App\Enums\StatementReviewStatus;
use App\Models\AuditTrail;
use App\Models\CreditRuleEvaluation;
use App\Models\LoanApplication;
use App\Models\MakerCheckerRequest;
use App\Models\MpesaStatementUpload;
use App\Services\Governance\MakerCheckerService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ApplicationReviewService
{
    /** Statuses that still wait for a credit check. */
    public const CHECKABLE_STATUSES = ['submitted', 'pending'];

    /** Status after a passed credit check: waiting for a second person. */
    public const STATUS_AWAITING_SECOND_APPROVER = 'approved_pending_checker';

    public function __construct(
        private readonly CreditUnderwritingEngine $engine,
        private readonly MakerCheckerService $makerChecker
    ) {
    }

    /**
     * Runs the underwriting engine once on a pending application,
     * and only when an accepted, in-date statement backs it.
     * Phase 11.5c: the typed-inflow override was removed; inflow now comes only from an accepted statement.
     *
     * @return array<string, mixed>
     */
    public function runCheck(int $applicationId, int $userId, string $ip, string $userAgent): array
    {
        return DB::transaction(function () use ($applicationId, $userId, $ip, $userAgent): array {
            $application = LoanApplication::query()
                ->where('id', $applicationId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCheckable($application);

            $hasValidStatement = MpesaStatementUpload::query()
                ->where('loan_application_id', $application->id)
                ->where('review_status', StatementReviewStatus::Accepted->value)
                ->whereDate('valid_until', '>=', now()->toDateString())
                ->exists();

            if (! $hasValidStatement) {
                throw new RuntimeException('Accept a valid M-Pesa statement before running the credit check.');
            }

            $oldStatus = (string) $application->status;

            // Page always shows the latest run only.
            CreditRuleEvaluation::query()
                ->where('loan_application_id', $application->id)
                ->delete();

            $result = $this->engine->evaluateApplication($application->id);

            LoanApplication::query()
                ->whereKey($application->id)
                ->update(['underwriter_id' => $userId]);

            AuditTrail::create([
                'user_id' => $userId,
                'action' => 'APPLICATION_CREDIT_CHECK_RUN',
                'auditable_type' => LoanApplication::class,
                'auditable_id' => $application->id,
                'old_values' => ['status' => $oldStatus],
                'new_values' => [
                    'status' => $result['status'],
                    'risk_band' => $result['risk_band'],
                    'approved_limit' => $result['approved_limit'],
                    'rules_passed' => $result['rules_passed'],
                    'rules_failed' => $result['rules_failed'],
                ],
                'ip_address' => substr($ip, 0, 45),
                'user_agent' => substr($userAgent, 0, 255),
            ]);

            return $result;
        });
    }

    /**
     * Raises a maker-checker request so a second person decides the application.
     * Only an application that passed the credit check can be sent, and only once at a time.
     *
     * @throws RuntimeException
     */
    public function requestFinalApproval(
        int $applicationId,
        int $userId,
        string $ip,
        string $userAgent
    ): MakerCheckerRequest {
        return DB::transaction(function () use ($applicationId, $userId, $ip, $userAgent): MakerCheckerRequest {
            $application = LoanApplication::query()
                ->where('id', $applicationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($application->status !== self::STATUS_AWAITING_SECOND_APPROVER) {
                throw new RuntimeException('Only an application that has passed the credit check can be sent for final approval.');
            }

            $alreadyPending = MakerCheckerRequest::query()
                ->where('action_type', MakerCheckerService::ACTION_APPROVE_LOAN_APPLICATION)
                ->where('target_entity_type', LoanApplication::class)
                ->where('target_entity_id', $application->id)
                ->where('status', 'pending')
                ->exists();

            if ($alreadyPending) {
                throw new RuntimeException('This application is already waiting for a second approver.');
            }

            $application->loadMissing(['borrower', 'product', 'underwriter']);
            $borrower = $application->borrower;
            $band = $borrower?->risk_band instanceof \BackedEnum ? $borrower->risk_band->value : $borrower?->risk_band;

            $request = $this->makerChecker->createPendingRequest(
                $userId,
                MakerCheckerService::ACTION_APPROVE_LOAN_APPLICATION,
                LoanApplication::class,
                $application->id,
                [
                    'application_number' => (string) $application->application_number,
                    'borrower' => (string) ($borrower?->full_name ?? 'Unknown'),
                    'product' => (string) ($application->product?->name ?? 'Unknown'),
                    'amount_requested' => bcadd((string) $application->requested_principal, '0', 2),
                    'credit_limit_set' => bcadd((string) ($borrower?->credit_limit ?? '0'), '0', 2),
                    'risk_band' => (string) ($band ?? 'Not set'),
                    'credit_check_by' => (string) ($application->underwriter?->name ?? 'Unknown'),
                ]
            );

            AuditTrail::create([
                'user_id' => $userId,
                'action' => 'APPLICATION_SENT_FOR_APPROVAL',
                'auditable_type' => LoanApplication::class,
                'auditable_id' => $application->id,
                'old_values' => ['status' => self::STATUS_AWAITING_SECOND_APPROVER],
                'new_values' => [
                    'status' => self::STATUS_AWAITING_SECOND_APPROVER,
                    'maker_checker_request_id' => $request->id,
                ],
                'ip_address' => substr($ip, 0, 45),
                'user_agent' => substr($userAgent, 0, 255),
            ]);

            return $request;
        });
    }

    private function assertCheckable(LoanApplication $application): void
    {
        if (! in_array($application->status, self::CHECKABLE_STATUSES, true)) {
            throw new RuntimeException('This application has already been checked.');
        }
    }
}