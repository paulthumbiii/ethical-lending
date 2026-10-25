<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\Enums\StatementReviewStatus;
use App\Models\Borrower;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\MpesaStatementUpload;
use Illuminate\Support\Carbon;

/**
 * Phase 11.2d / 11.5. Builds the borrower-facing timeline.
 * 11.5: the credit check step now states the approved amount, and the Approved step
 * states what the borrower receives and which fees are added (lending that shows its working).
 */
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

        $offer = $creditCheckPassed ? $this->offer($application) : null;

        if ($creditCheckPassed) {
            $steps[] = $this->step('credit_check', 'Credit check', $this->approvedAmountText($application, $offer), self::STATE_DONE);
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

        $steps[] = $this->step('approved', 'Approved', $approved ? $this->feeText($offer) : null, $approved ? self::STATE_DONE : self::STATE_UPCOMING);

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
     * Works out what the borrower will be sent and which fees will be added.
     * Mirrors LoanOriginationService (lesser of request and limit, whole shillings)
     * and LoanFeeService (percentage rounded half up to the cent).
     *
     * @return array{amount: string, fees: list<array{name: string, amount: string}>, fee_total: string}|null
     */
    private function offer(LoanApplication $application): ?array
    {
        $borrower = Borrower::query()->find($application->borrower_id);

        if ($borrower === null) {
            return null;
        }

        $requested = (string) $application->requested_principal;
        $limit = (string) $borrower->credit_limit;
        $lesser = bccomp($requested, $limit, 2) <= 0 ? $requested : $limit;
        $amount = bcadd($lesser, '0', 0);

        if (bccomp($amount, '0', 0) <= 0) {
            return null;
        }

        $fees = [];
        $total = '0.00';
        $product = LoanProduct::query()->with('feeMatrices')->find($application->loan_product_id);

        foreach ($product?->feeMatrices ?? [] as $row) {
            $type = (string) $row->calculation_type;

            if ($type === 'percentage') {
                $fee = bcadd(bcdiv(bcmul($amount, (string) $row->fee_percentage, 4), '100', 4), '0.005', 2);
            } elseif ($type === 'fixed' || $type === 'flat') {
                $fee = bcadd((string) $row->fee_amount, '0', 2);
            } else {
                continue;
            }

            $fees[] = ['name' => (string) $row->fee_name, 'amount' => $fee];
            $total = bcadd($total, $fee, 2);
        }

        return ['amount' => $amount, 'fees' => $fees, 'fee_total' => $total];
    }

    private function approvedAmountText(LoanApplication $application, ?array $offer): string
    {
        if ($offer === null) {
            return 'Passed.';
        }

        $approved = 'KES ' . number_format((float) $offer['amount'], 0);

        if (bccomp($offer['amount'], bcadd((string) $application->requested_principal, '0', 0), 0) < 0) {
            return 'Passed. Approved amount: ' . $approved
                . ' (you asked for KES ' . number_format((float) $application->requested_principal, 0) . ').';
        }

        return 'Passed. Approved amount: ' . $approved . '.';
    }

    private function feeText(?array $offer): ?string
    {
        if ($offer === null) {
            return null;
        }

        $receive = 'KES ' . number_format((float) $offer['amount'], 0);

        if ($offer['fees'] === []) {
            return 'You will receive ' . $receive . '.';
        }

        $parts = array_map(
            static fn (array $f): string => $f['name'] . ' KES ' . number_format((float) $f['amount'], 2),
            $offer['fees']
        );
        $owed = bcadd($offer['amount'], $offer['fee_total'], 2);

        return 'You will receive ' . $receive . '. We add ' . implode(', ', $parts)
            . ' to what you owe, so you owe KES ' . number_format((float) $owed, 2) . ' plus interest.';
    }

    /**
     * @return array{key: string, title: string, detail: ?string, state: string}
     */
    private function step(string $key, string $title, ?string $detail, string $state): array
    {
        return ['key' => $key, 'title' => $title, 'detail' => $detail, 'state' => $state];
    }
}