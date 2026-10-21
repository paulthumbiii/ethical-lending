<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\Enums\StatementReviewStatus;
use App\Models\LoanApplication;
use App\Models\MpesaStatementUpload;
use Illuminate\Support\Carbon;

final class ApplicationTimelineService
{
    public const STATE_DONE = 'done';
    public const STATE_CURRENT = 'current';
    public const STATE_UPCOMING = 'upcoming';
    public const STATE_PROBLEM = 'problem';

    private const STATUS_PENDING_CHECKER = 'approved_pending_checker';
    private const STATUS_APPROVED = 'approved';

    /**
     * @return list<array{key: string, title: string, detail: ?string, state: string}>
     */
    public function build(LoanApplication $application): array
    {
        $statement = MpesaStatementUpload::query()
            ->where('loan_application_id', $application->id)
            ->latest('id')
            ->first();

        $status = (string) $application->status;
        $creditCheckPassed = in_array($status, [self::STATUS_PENDING_CHECKER, self::STATUS_APPROVED], true);
        $approved = $status === self::STATUS_APPROVED;

        $loan = $application->loan;
        $paid = $loan !== null && $loan->disbursed_at !== null;

        // A passed credit check always means a statement was accepted (the gate enforces it).
        $statementAccepted = $creditCheckPassed || $statement?->review_status === StatementReviewStatus::Accepted;
        $statementRejected = ! $statementAccepted && $statement?->review_status === StatementReviewStatus::Rejected;

        $steps = [];

        $steps[] = $this->step('received', 'Application received', null, self::STATE_DONE);

        $steps[] = $statement !== null
            ? $this->step('statement_received', 'Statement received', null, self::STATE_DONE)
            : $this->step('statement_received', 'Statement received', 'Please upload your M-Pesa statement below.', self::STATE_CURRENT);

        if ($statementAccepted) {
            $detail = $statement?->valid_until !== null
                ? 'Accepted. Valid until ' . $statement->valid_until->format('d M Y') . '.'
                : 'Accepted.';
            $steps[] = $this->step('statement_checked', 'Statement checked', $detail, self::STATE_DONE);
        } elseif ($statementRejected) {
            $reason = $statement?->review_notes;
            $steps[] = $this->step(
                'statement_checked',
                'Statement checked',
                'Please upload again.' . ($reason ? ' ' . $reason : ''),
                self::STATE_PROBLEM
            );
        } elseif ($statement !== null) {
            $steps[] = $this->step('statement_checked', 'Statement checked', 'A credit officer is checking it.', self::STATE_CURRENT);
        } else {
            $steps[] = $this->step('statement_checked', 'Statement checked', null, self::STATE_UPCOMING);
        }

        if ($creditCheckPassed) {
            $steps[] = $this->step('credit_check', 'Credit check', 'Passed.', self::STATE_DONE);
        } elseif ($statementAccepted) {
            $steps[] = $this->step('credit_check', 'Credit check', 'Waiting for the credit officer to run it.', self::STATE_CURRENT);
        } else {
            $steps[] = $this->step('credit_check', 'Credit check', null, self::STATE_UPCOMING);
        }

        if ($approved) {
            $steps[] = $this->step('second_approval', 'Final approval by a second person', null, self::STATE_DONE);
        } elseif ($creditCheckPassed) {
            $steps[] = $this->step(
                'second_approval',
                'Final approval by a second person',
                'A second staff member must confirm before any money is sent.',
                self::STATE_CURRENT
            );
        } else {
            $steps[] = $this->step('second_approval', 'Final approval by a second person', null, self::STATE_UPCOMING);
        }

        $steps[] = $this->step('approved', 'Approved', null, $approved ? self::STATE_DONE : self::STATE_UPCOMING);

        if ($paid) {
            $sentOn = Carbon::parse($loan->disbursed_at)->timezone('Africa/Nairobi')->format('d M Y');
            $steps[] = $this->step('money_sent', 'Money sent', 'Sent to your M-Pesa on ' . $sentOn . '.', self::STATE_DONE);
        } elseif ($approved) {
            $steps[] = $this->step('money_sent', 'Money sent', 'Waiting for payout.', self::STATE_CURRENT);
        } else {
            $steps[] = $this->step('money_sent', 'Money sent', null, self::STATE_UPCOMING);
        }

        return $steps;
    }

    /**
     * @return array{key: string, title: string, detail: ?string, state: string}
     */
    private function step(string $key, string $title, ?string $detail, string $state): array
    {
        return ['key' => $key, 'title' => $title, 'detail' => $detail, 'state' => $state];
    }
}