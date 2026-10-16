<?php

declare(strict_types=1);

namespace App\Services\Governance;

use App\Models\AuditTrail;
use App\Models\LoanApplication;
use App\Models\MakerCheckerRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class MakerCheckerService
{
    /**
     * Creates a pending dual-control authorization request.
     *
     * @param int $makerUserId
     * @string $actionType (e.g., 'APPROVE_LOAN_DISBURSEMENT', 'WAIVE_INTEREST')
     * @string $targetEntityType
     * @int $targetEntityId
     * @array $payload
     * @return MakerCheckerRequest
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
     * @param int $requestId
     * @param int $checkerUserId
     * @param string|null $checkerNotes
     * @return array
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

            // Dual-Control Requirement: Checker MUST NOT be the Maker
            if ($request->maker_user_id === $checkerUserId) {
                Log::channel('fintech')->warning("Dual-Control Violation Attempt: User #{$checkerUserId} attempted self-approval on Request #{$requestId}");
                throw new RuntimeException("Dual-Control Violation: You cannot authorize a request you initiated.");
            }

            $request->update([
                'status' => 'approved',
                'checker_user_id' => $checkerUserId,
                'checker_notes' => $checkerNotes,
                'actioned_at' => now(),
            ]);

            // Execute specific state mutation based on action type
            if ($request->action_type === 'APPROVE_LOAN_APPLICATION') {
                /** @var LoanApplication|null $app */
                $app = LoanApplication::where('id', $request->target_entity_id)->first();
                if ($app) {
                    $app->update(['status' => 'approved']);
                }
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
     * Rejects a pending dual-control request.
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

            if ($request->maker_user_id === $checkerUserId) {
                throw new RuntimeException("Dual-Control Violation: You cannot reject/action a request you initiated.");
            }

            $request->update([
                'status' => 'rejected',
                'checker_user_id' => $checkerUserId,
                'checker_notes' => $reason,
                'actioned_at' => now(),
            ]);

            return [
                'request_id' => $request->id,
                'status' => 'rejected',
            ];
        });
    }
}