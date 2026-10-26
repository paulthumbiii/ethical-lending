<?php

declare(strict_types=1);

namespace App\Services\Governance;

use App\Models\AuditTrail;
use App\Models\LoanApplication;
use App\Models\MakerCheckerRequest;
use App\Services\Customer\BorrowerNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class MakerCheckerService
{
    public const ACTION_APPROVE_LOAN_APPLICATION = 'APPROVE_LOAN_APPLICATION';

    /** The only application status a second approver may act on. */
    public const APPLICATION_AWAITING_STATUS = 'approved_pending_checker';

    /** Shown to the borrower. Staff notes stay on the request, never on the borrower's screen. */
    public const BORROWER_REJECTION_REASON = 'This application did not pass final review.';

    /**
     * Creates a pending dual-control authorization request.
     *
     * @param array<string, mixed> $payload
     */
    public function createPendingRequest(
        int $makerUserId,
        string $actionType,
        string $targetEntityType,
        int $targetEntityId,
        array $payload = []
    ): MakerCheckerRequest {
        return MakerCheckerRequest::create([
            'maker_user_id' => $makerUserId,
            'action_type' => $actionType,
            'target_entity_type' => $targetEntityType,
            'target_entity_id' => $targetEntityId,
            'payload' => $payload,
            'status' => 'pending',
        ]);
    }

    /**
     * Authorizes and executes a pending request under strict non-self-approval enforcement.
     *
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    public function authorizeRequest(int $requestId, int $checkerUserId, ?string $checkerNotes = null): array
    {
        return DB::transaction(function () use ($requestId, $checkerUserId, $checkerNotes) {
            /** @var MakerCheckerRequest $request */
            $request = MakerCheckerRequest::where('id', $requestId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status !== 'pending') {
                throw new RuntimeException("Maker-Checker Request #{$requestId} is not in pending status.");
            }

            // Dual-Control Requirement: Checker MUST NOT be the Maker.
            // Compared as integers so a driver that returns ids as strings cannot slip past the check.
            if ((int) $request->maker_user_id === $checkerUserId) {
                Log::channel('fintech')->warning("Dual-Control Violation Attempt: User #{$checkerUserId} attempted self-approval on Request #{$requestId}");
                throw new RuntimeException("Dual-Control Violation: You cannot authorize a request you initiated.");
            }

            // Fail closed: an application can only be approved while it is still awaiting a second approver.
            $application = null;
            if ($request->action_type === self::ACTION_APPROVE_LOAN_APPLICATION) {
                $application = LoanApplication::where('id', $request->target_entity_id)
                    ->lockForUpdate()
                    ->first();

                if ($application === null || $application->status !== self::APPLICATION_AWAITING_STATUS) {
                    throw new RuntimeException('This application is no longer waiting for final approval.');
                }
            }

            $request->update([
                'status' => 'approved',
                'checker_user_id' => $checkerUserId,
                'checker_notes' => $checkerNotes,
                'actioned_at' => now(),
            ]);

            if ($application !== null) {
                $application->update(['status' => 'approved']);

                AuditTrail::create([
                    'user_id' => $checkerUserId,
                    'action' => 'APPLICATION_FINAL_APPROVED',
                    'auditable_type' => LoanApplication::class,
                    'auditable_id' => $application->id,
                    'old_values' => ['status' => self::APPLICATION_AWAITING_STATUS],
                    'new_values' => ['status' => 'approved', 'maker_checker_request_id' => $request->id],
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'SYSTEM',
                ]);

                app(BorrowerNotificationService::class)->notify(
                    (int) $application->borrower_id,
                    'application_approved',
                    'Application approved',
                    'Your loan application was approved. The money will be sent to your M-Pesa once our team releases it.'
                );
            }

            // Record immutable Audit Trail
            AuditTrail::create([
                'user_id' => $checkerUserId,
                'action' => 'MAKER_CHECKER_AUTHORIZED',
                'auditable_type' => MakerCheckerRequest::class,
                'auditable_id' => $request->id,
                'old_values' => ['status' => 'pending'],
                'new_values' => [
                    'status' => 'approved',
                    'action_type' => $request->action_type,
                    'checker_user_id' => $checkerUserId,
                ],
                'ip_address' => request()->ip() ?? '127.0.0.1',
                'user_agent' => request()->userAgent() ?? 'SYSTEM',
            ]);

            Log::channel('fintech')->info("Maker-Checker Request #{$requestId} [{$request->action_type}] authorized by Checker #{$checkerUserId}");

            return [
                'request_id' => $request->id,
                'action_type' => $request->action_type,
                'status' => 'approved',
            ];
        });
    }

    /**
     * Rejects a pending dual-control request and records the decision on the audit trail.
     * A rejected loan-application request also closes the application, so it is never left hanging.
     *
     * @return array<string, mixed>
     */
    public function rejectRequest(int $requestId, int $checkerUserId, string $reason): array
    {
        return DB::transaction(function () use ($requestId, $checkerUserId, $reason) {
            /** @var MakerCheckerRequest $request */
            $request = MakerCheckerRequest::where('id', $requestId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status !== 'pending') {
                throw new RuntimeException("Maker-Checker Request #{$requestId} is not in pending status.");
            }

            if ((int) $request->maker_user_id === $checkerUserId) {
                Log::channel('fintech')->warning("Dual-Control Violation Attempt: User #{$checkerUserId} attempted to reject own Request #{$requestId}");
                throw new RuntimeException("Dual-Control Violation: You cannot reject/action a request you initiated.");
            }

            // Only an application still awaiting a second approver is closed. Anything else is left untouched.
            $application = null;
            if ($request->action_type === self::ACTION_APPROVE_LOAN_APPLICATION) {
                $application = LoanApplication::where('id', $request->target_entity_id)
                    ->lockForUpdate()
                    ->first();

                if ($application !== null && $application->status !== self::APPLICATION_AWAITING_STATUS) {
                    $application = null;
                }
            }

            $request->update([
                'status' => 'rejected',
                'checker_user_id' => $checkerUserId,
                'checker_notes' => $reason,
                'actioned_at' => now(),
            ]);

            if ($application !== null) {
                $application->update([
                    'status' => 'rejected',
                    'rejection_reason' => self::BORROWER_REJECTION_REASON,
                ]);

                AuditTrail::create([
                    'user_id' => $checkerUserId,
                    'action' => 'APPLICATION_FINAL_REJECTED',
                    'auditable_type' => LoanApplication::class,
                    'auditable_id' => $application->id,
                    'old_values' => ['status' => self::APPLICATION_AWAITING_STATUS],
                    'new_values' => ['status' => 'rejected', 'maker_checker_request_id' => $request->id],
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'SYSTEM',
                ]);

                app(BorrowerNotificationService::class)->notify(
                    (int) $application->borrower_id,
                    'application_rejected',
                    'Application not approved',
                    self::BORROWER_REJECTION_REASON
                );
            }

            AuditTrail::create([
                'user_id' => $checkerUserId,
                'action' => 'MAKER_CHECKER_REJECTED',
                'auditable_type' => MakerCheckerRequest::class,
                'auditable_id' => $request->id,
                'old_values' => ['status' => 'pending'],
                'new_values' => [
                    'status' => 'rejected',
                    'action_type' => $request->action_type,
                    'checker_user_id' => $checkerUserId,
                    'reason' => $reason,
                ],
                'ip_address' => request()->ip() ?? '127.0.0.1',
                'user_agent' => request()->userAgent() ?? 'SYSTEM',
            ]);

            Log::channel('fintech')->info("Maker-Checker Request #{$requestId} [{$request->action_type}] rejected by Checker #{$checkerUserId}");

            return [
                'request_id' => $request->id,
                'status' => 'rejected',
            ];
        });
    }
}