<?php

declare(strict_types=1);

namespace App\Services\Servicing;

use App\Enums\LoanStatus;
use App\Exceptions\Fintech\RestructuringNotEligibleException;
use App\Models\AuditTrail;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanLedgerEntry;
use App\Models\OutboxEvent;
use App\Models\RestructuringProposal;
use App\Models\RestructuringSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class RestructuringEngine
{
    private const MINIMUM_ELIGIBILITY_RATIO = '0.30';

    /**
     * Calculates the borrower's historical on-time repayment ratio (0.00 to 1.00).
     */
    public function calculateRepaymentRatio(Borrower $borrower): string
    {
        $loans = Loan::query()
            ->where('borrower_id', $borrower->id)
            ->with('ledgerEntries')
            ->get();

        if ($loans->isEmpty()) {
            return '1.00'; // New borrower baseline
        }

        $totalDisbursed = '0.00';
        $totalRepaid = '0.00';

        foreach ($loans as $loan) {
            $totalDisbursed = bcadd($totalDisbursed, (string) $loan->principal_disbursed, 2);

            $repaid = $loan->ledgerEntries
                ->where('entry_type', 'repayment')
                ->sum('credit');

            $totalRepaid = bcadd($totalRepaid, (string) $repaid, 2);
        }

        if (bccomp($totalDisbursed, '0.00', 2) === 0) {
            return '1.00';
        }

        // Ratio = min(1.00, totalRepaid / totalDisbursed)
        $ratio = bcdiv($totalRepaid, $totalDisbursed, 2);

        return bccomp($ratio, '1.00', 2) > 0 ? '1.00' : $ratio;
    }

    /**
     * Generates a tailored restructuring proposal with penalty fee forgiveness.
     */
    public function generateProposal(
        Loan $loan,
        int $tenureExtensionDays = 30,
        ?string $concessionRate = null
    ): RestructuringProposal {
        return DB::transaction(function () use ($loan, $tenureExtensionDays, $concessionRate): RestructuringProposal {
            $lockedLoan = Loan::query()->where('id', $loan->id)->lockForUpdate()->firstOrFail();

            if (in_array($lockedLoan->status->value, ['settled', 'written_off'], true)) {
                throw new RestructuringNotEligibleException(
                    loanId: $lockedLoan->id,
                    repaymentRatio: '0.00',
                    reason: 'Cannot restructure a settled or written-off facility.'
                );
            }

            // 1. Evaluate Behavioral Eligibility
            $ratio = $this->calculateRepaymentRatio($lockedLoan->borrower);

            if (bccomp($ratio, self::MINIMUM_ELIGIBILITY_RATIO, 2) < 0) {
                throw new RestructuringNotEligibleException(
                    loanId: $lockedLoan->id,
                    repaymentRatio: $ratio,
                    reason: 'Historical repayment ratio falls below the minimum 30% threshold.'
                );
            }

            // 2. Read latest balances from immutable ledger
            $latest = LoanLedgerEntry::query()
                ->where('loan_id', $lockedLoan->id)
                ->latest('id')
                ->first();

            $principalOwed = $latest !== null ? (string) $latest->running_principal_balance : (string) $lockedLoan->principal_disbursed;
            $interestOwed = $latest !== null ? (string) $latest->running_interest_balance : '0.00';
            $feesOwed = $latest !== null ? (string) $latest->running_fees_balance : '0.00';

            // 3. Relief Concessions: Forgive 100% of penalty fees
            $forgivenPenalties = $feesOwed;
            $forgivenInterest = '0.00'; // Retain legitimate interest, forgive penalties
            $capitalizedPrincipal = $principalOwed;
            $newRate = $concessionRate ?? (string) $lockedLoan->annual_interest_rate;
            $now = CarbonImmutable::now();

            $proposal = RestructuringProposal::create([
                'loan_id'                  => $lockedLoan->id,
                'proposal_code'            => 'RST-' . strtoupper(Str::random(8)),
                'status'                   => 'offered',
                'forgiven_interest'        => $forgivenInterest,
                'forgiven_penalties'       => $forgivenPenalties,
                'capitalized_principal'    => $capitalizedPrincipal,
                'new_interest_rate'        => $newRate,
                'tenure_extension_days'    => $tenureExtensionDays,
                'repayment_ratio_snapshot' => $ratio,
                'expires_at'               => $now->addDays(7),
                'accepted_at'              => null,
            ]);

            // 4. Generate Re-amortization Installment Schedule (e.g. 2 bi-weekly installments)
            $totalRemaining = bcadd($capitalizedPrincipal, $interestOwed, 2);
            $installmentAmount = bcdiv($totalRemaining, '2.00', 2);
            $remainder = bcsub($totalRemaining, bcmul($installmentAmount, '2.00', 2), 2);

            RestructuringSchedule::create([
                'restructuring_proposal_id' => $proposal->id,
                'installment_number'        => 1,
                'due_date'                  => $now->addDays((int) ($tenureExtensionDays / 2))->toDateString(),
                'installment_amount'        => $installmentAmount,
                'status'                    => 'pending',
            ]);

            RestructuringSchedule::create([
                'restructuring_proposal_id' => $proposal->id,
                'installment_number'        => 2,
                'due_date'                  => $now->addDays($tenureExtensionDays)->toDateString(),
                'installment_amount'        => bcadd($installmentAmount, $remainder, 2),
                'status'                    => 'pending',
            ]);

            return $proposal;
        });
    }

    /**
     * Executes the acceptance of a restructuring proposal by the borrower.
     */
    public function acceptProposal(RestructuringProposal $proposal): Loan
    {
        return DB::transaction(function () use ($proposal): Loan {
            $lockedProposal = RestructuringProposal::query()
                ->where('id', $proposal->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedProposal->status !== 'offered') {
                throw new \DomainException("Proposal is already {$lockedProposal->status} and cannot be accepted.");
            }

            if (CarbonImmutable::now()->isAfter($lockedProposal->expires_at)) {
                $lockedProposal->update(['status' => 'expired']);
                throw new \DomainException('Restructuring proposal has expired.');
            }

            $loan = Loan::query()->where('id', $lockedProposal->loan_id)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now();

            // 1. Mark proposal as accepted
            $lockedProposal->update([
                'status'      => 'accepted',
                'accepted_at' => $now,
            ]);

            // 2. Post ledger entry clearing forgiven penalties
            if (bccomp((string) $lockedProposal->forgiven_penalties, '0.00', 2) > 0) {
                $latest = LoanLedgerEntry::where('loan_id', $loan->id)->latest('id')->first();
                $newPrincipal = $latest ? $latest->running_principal_balance : $loan->principal_disbursed;
                $newInterest = $latest ? $latest->running_interest_balance : '0.00';
                $newTotal = bcadd((string) $newPrincipal, (string) $newInterest, 2);

                LoanLedgerEntry::create([
                    'loan_id'                   => $loan->id,
                    'entry_type'                => 'restructure_waiver',
                    'debit'                     => '0.00',
                    'credit'                    => $lockedProposal->forgiven_penalties,
                    'running_principal_balance' => $newPrincipal,
                    'running_interest_balance'  => $newInterest,
                    'running_fees_balance'      => '0.00',
                    'total_running_balance'     => $newTotal,
                    'reference'                 => $lockedProposal->proposal_code,
                    'notes'                     => sprintf(
                        'Penalty fee waiver of KES %s applied under Restructure Agreement #%s',
                        $lockedProposal->forgiven_penalties,
                        $lockedProposal->proposal_code
                    ),
                ]);
            }

            // 3. Update Loan status and extended due date
            $loan->update([
                'status'   => LoanStatus::RESTRUCTURED,
                'due_date' => CarbonImmutable::parse($loan->due_date ?? $now)->addDays($lockedProposal->tenure_extension_days),
            ]);

            // 4. Dispatch cryptographically chained Outbox Event
            $prevEvent = OutboxEvent::query()->latest('id')->first();
            $prevHash = $prevEvent ? $prevEvent->current_event_hash : str_repeat('0', 64);

            $payload = [
                'loan_id'            => $loan->id,
                'loan_number'        => $loan->loan_number,
                'proposal_code'      => $lockedProposal->proposal_code,
                'forgiven_penalties' => (string) $lockedProposal->forgiven_penalties,
                'extended_days'      => $lockedProposal->tenure_extension_days,
                'restructured_at'    => $now->toIso8601String(),
            ];

            $currentHash = hash('sha256', $prevHash . 'loan.restructured' . json_encode($payload));

            OutboxEvent::create([
                'aggregate_type'      => Loan::class,
                'aggregate_id'        => $loan->id,
                'event_name'          => 'loan.restructured',
                'payload'             => $payload,
                'previous_event_hash' => $prevHash,
                'current_event_hash'  => $currentHash,
                'is_published'        => false,
                'retry_count'         => 0,
            ]);

            AuditTrail::create([
                'user_id'        => auth()->id(),
                'action'         => 'LOAN_RESTRUCTURED',
                'auditable_type' => Loan::class,
                'auditable_id'   => $loan->id,
                'old_values'     => ['status' => $loan->getOriginal('status')],
                'new_values'     => [
                    'status'        => LoanStatus::RESTRUCTURED->value,
                    'proposal_code' => $lockedProposal->proposal_code,
                ],
                'ip_address'     => request()->ip() ?? '127.0.0.1',
                'user_agent'     => request()->userAgent() ?? 'System/CLI',
            ]);

            return $loan;
        });
    }
}