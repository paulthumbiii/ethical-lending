<?php

declare(strict_types=1);

namespace App\Services\Underwriting;

use App\Enums\StatementReviewStatus;
use App\Models\AuditTrail;
use App\Models\BorrowerFinancialProfile;
use App\Models\LoanApplication;
use App\Models\MpesaStatementUpload;
use App\Services\Customer\BorrowerNotificationService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class StatementReviewService
{
    /** How long an accepted statement counts as evidence. */
    public const VALID_DAYS = 30;

    public function accept(
        int $uploadId,
        int $userId,
        string $verifiedInflow,
        ?string $notes,
        string $ip,
        string $userAgent
    ): void {
        $verifiedInflow = bcadd($verifiedInflow, '0', 2);
        $notes = $notes !== null ? trim($notes) : null;

        DB::transaction(function () use ($uploadId, $userId, $verifiedInflow, $notes, $ip, $userAgent): void {
            $upload = MpesaStatementUpload::query()
                ->where('id', $uploadId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertAwaitingReview($upload);

            $application = $upload->loan_application_id !== null
                ? LoanApplication::query()->where('id', $upload->loan_application_id)->lockForUpdate()->first()
                : null;

            if ($application === null
                || ! in_array($application->status, ApplicationReviewService::CHECKABLE_STATUSES, true)) {
                throw new RuntimeException('This application is no longer waiting for a check.');
            }

            $flags = is_array($upload->flags) ? $upload->flags : [];
            if ($flags !== [] && ($notes === null || $notes === '')) {
                throw new RuntimeException('This statement has warning flags. Write a note explaining why you are accepting it.');
            }

            $profile = BorrowerFinancialProfile::query()
                ->where('borrower_id', $application->borrower_id)
                ->lockForUpdate()
                ->first();

            $oldInflow = $profile !== null
                ? bcadd((string) $profile->mpesa_average_monthly_inflow, '0', 2)
                : null;

            if ($profile === null) {
                BorrowerFinancialProfile::create([
                    'borrower_id' => $application->borrower_id,
                    'monthly_income' => '0.00',
                    'monthly_expenses' => '0.00',
                    'existing_debt_obligations' => '0.00',
                    'mpesa_average_monthly_inflow' => $verifiedInflow,
                ]);
            } else {
                $profile->update(['mpesa_average_monthly_inflow' => $verifiedInflow]);
            }

            $validUntil = now()->addDays(self::VALID_DAYS)->toDateString();

            $upload->update([
                'review_status' => StatementReviewStatus::Accepted->value,
                'reviewed_by_user_id' => $userId,
                'reviewed_at' => now(),
                'review_notes' => $notes !== '' ? $notes : null,
                'valid_until' => $validUntil,
                'pdf_password_encrypted' => null,
            ]);

            $this->audit($userId, 'STATEMENT_ACCEPTED', $upload->id, $ip, $userAgent, [
                'mpesa_average_monthly_inflow' => $oldInflow,
            ], [
                'mpesa_average_monthly_inflow' => $verifiedInflow,
                'valid_until' => $validUntil,
                'flags_acknowledged' => $flags,
                'application_id' => $application->id,
            ]);

            app(BorrowerNotificationService::class)->notify(
                (int) $application->borrower_id,
                'statement_accepted',
                'Statement accepted',
                'A credit officer checked your M-Pesa statement. Your application moves to the next step.'
            );
        });
    }

    public function reject(
        int $uploadId,
        int $userId,
        string $reason,
        string $ip,
        string $userAgent
    ): void {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('A reason is required to reject a statement.');
        }

        DB::transaction(function () use ($uploadId, $userId, $reason, $ip, $userAgent): void {
            $upload = MpesaStatementUpload::query()
                ->where('id', $uploadId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertAwaitingReview($upload);

            $upload->update([
                'review_status' => StatementReviewStatus::Rejected->value,
                'reviewed_by_user_id' => $userId,
                'reviewed_at' => now(),
                'review_notes' => $reason,
                'pdf_password_encrypted' => null,
            ]);

            $this->audit($userId, 'STATEMENT_REJECTED', $upload->id, $ip, $userAgent, null, [
                'reason' => $reason,
                'application_id' => $upload->loan_application_id,
            ]);

            app(BorrowerNotificationService::class)->notify(
                (int) $upload->borrower_id,
                'statement_rejected',
                'Please upload your statement again',
                'We could not accept your M-Pesa statement. Open Apply to see why and upload a new one.'
            );
        });
    }

    /** Records that a staff member opened the document. */
    public function recordView(int $uploadId, int $userId, string $ip, string $userAgent): void
    {
        $this->audit($userId, 'STATEMENT_VIEWED', $uploadId, $ip, $userAgent, null, null);
    }

    /**
     * Returns the borrower's PDF password, or null when there is none to show.
     * Every reveal is audited. The password itself is never written to the audit row.
     */
    public function revealPassword(int $uploadId, int $userId, string $ip, string $userAgent): ?string
    {
        $upload = MpesaStatementUpload::query()->where('id', $uploadId)->firstOrFail();

        if ($upload->review_status !== StatementReviewStatus::AwaitingReview
            || $upload->pdf_password_encrypted === null) {
            return null;
        }

        try {
            $password = Crypt::decryptString((string) $upload->pdf_password_encrypted);
        } catch (DecryptException) {
            return null;
        }

        $this->audit($userId, 'STATEMENT_PASSWORD_REVEALED', $upload->id, $ip, $userAgent, null, null);

        return $password;
    }

    private function assertAwaitingReview(MpesaStatementUpload $upload): void
    {
        if ($upload->review_status !== StatementReviewStatus::AwaitingReview) {
            throw new RuntimeException('This statement has already been decided or replaced.');
        }
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    private function audit(int $userId, string $action, int $uploadId, string $ip, string $userAgent, ?array $old, ?array $new): void
    {
        AuditTrail::create([
            'user_id' => $userId,
            'action' => $action,
            'auditable_type' => MpesaStatementUpload::class,
            'auditable_id' => $uploadId,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => substr($ip, 0, 45),
            'user_agent' => substr($userAgent, 0, 255),
        ]);
    }
}