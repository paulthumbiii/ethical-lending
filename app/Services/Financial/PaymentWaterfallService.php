<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Enums\LoanStatus;
use App\Exceptions\Fintech\InvalidPaymentAmountException;
use App\Models\AuditTrail;
use App\Models\Loan;
use App\Models\LoanLedgerEntry;
use App\Models\OutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PaymentWaterfallService
{
    /**
     * Executes the statutory payment waterfall under a pessimistic row lock.
     * Order of allocation: Fees & Penalties -> Accrued Interest -> Principal.
     *
     * @return array{
     *     allocated_fees: string,
     *     allocated_interest: string,
     *     allocated_principal: string,
     *     overpayment_credit: string,
     *     new_total_balance: string,
     *     is_settled: bool
     * }
     */
    public function executeWaterfall(
        Loan $loan,
        string $amount,
        string $reference,
        string $source = 'MPESA_C2B'
    ): array {
        // Step 1: Validate numeric positive amount
        if (!is_numeric($amount) || bccomp($amount, '0.00', 2) <= 0) {
            throw new InvalidPaymentAmountException($amount);
        }

        return DB::transaction(function () use ($loan, $amount, $reference, $source): array {
            // Step 2: Pessimistic Row Lock on Loan
            $lockedLoan = Loan::query()->where('id', $loan->id)->lockForUpdate()->firstOrFail();

            // Step 3: Retrieve current running balances from the latest immutable ledger line
            $latestEntry = LoanLedgerEntry::query()
                ->where('loan_id', $lockedLoan->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $currentFees = $latestEntry !== null ? (string) $latestEntry->running_fees_balance : '0.00';
            $currentInterest = $latestEntry !== null ? (string) $latestEntry->running_interest_balance : '0.00';
            $currentPrincipal = $latestEntry !== null ? (string) $latestEntry->running_principal_balance : (string) $lockedLoan->principal_disbursed;

            $unallocated = $amount;

            // Step 4: Allocate to Fees & Penalties first
            $allocatedFees = '0.00';
            if (bccomp($currentFees, '0.00', 2) > 0) {
                $allocatedFees = bccomp($unallocated, $currentFees, 2) >= 0 ? $currentFees : $unallocated;
                $unallocated = bcsub($unallocated, $allocatedFees, 2);
            }

            // Step 5: Allocate to Accrued Interest second
            $allocatedInterest = '0.00';
            if (bccomp($unallocated, '0.00', 2) > 0 && bccomp($currentInterest, '0.00', 2) > 0) {
                $allocatedInterest = bccomp($unallocated, $currentInterest, 2) >= 0 ? $currentInterest : $unallocated;
                $unallocated = bcsub($unallocated, $allocatedInterest, 2);
            }

            // Step 6: Allocate to Outstanding Principal third
            $allocatedPrincipal = '0.00';
            if (bccomp($unallocated, '0.00', 2) > 0 && bccomp($currentPrincipal, '0.00', 2) > 0) {
                $allocatedPrincipal = bccomp($unallocated, $currentPrincipal, 2) >= 0 ? $currentPrincipal : $unallocated;
                $unallocated = bcsub($unallocated, $allocatedPrincipal, 2);
            }

            // Step 7: Any remaining unallocated cash becomes Overpayment Credit
            $overpaymentCredit = $unallocated;

            // Step 8: Compute new running balances
            $newFees = bcsub($currentFees, $allocatedFees, 2);
            $newInterest = bcsub($currentInterest, $allocatedInterest, 2);
            $newPrincipal = bcsub($currentPrincipal, $allocatedPrincipal, 2);
            $newTotalBalance = bcadd(bcadd($newPrincipal, $newInterest, 2), $newFees, 2);

            // Total credit applied against the loan
            $totalCreditApplied = bcadd(bcadd($allocatedFees, $allocatedInterest, 2), $allocatedPrincipal, 2);

            // Step 9: Write immutable append-only ledger entry
            LoanLedgerEntry::create([
                'loan_id'                   => $lockedLoan->id,
                'entry_type'                => 'repayment',
                'debit'                     => '0.00',
                'credit'                    => $totalCreditApplied,
                'running_principal_balance' => $newPrincipal,
                'running_interest_balance'  => $newInterest,
                'running_fees_balance'      => $newFees,
                'total_running_balance'     => $newTotalBalance,
                'reference'                 => $reference,
                'notes'                     => sprintf(
                    'Repayment via %s [Ref: %s]. Waterfall split: Fees: KES %s | Interest: KES %s | Principal: KES %s | Overpayment: KES %s',
                    $source,
                    $reference,
                    $allocatedFees,
                    $allocatedInterest,
                    $allocatedPrincipal,
                    $overpaymentCredit
                ),
            ]);

            // Step 10: Log waterfall breakdown
            DB::table('payment_waterfall_logs')->insert([
                'loan_id'                 => $lockedLoan->id,
                'payment_reference'       => $reference,
                'total_amount_received'   => $amount,
                'allocated_to_fees'       => $allocatedFees,
                'allocated_to_interest'   => $allocatedInterest,
                'allocated_to_principal'  => $allocatedPrincipal,
                'overpayment_amount'      => $overpaymentCredit,
                'created_at'              => CarbonImmutable::now(),
            ]);

            // Step 11: Check for full loan settlement
            $isSettled = bccomp($newTotalBalance, '0.00', 2) === 0;
            if ($isSettled) {
                $lockedLoan->update([
                    'status' => LoanStatus::SETTLED,
                ]);

                // Cryptographically chained Outbox Event for settlement
                $prevEvent = OutboxEvent::query()->latest('id')->first();
                $prevHash = $prevEvent ? $prevEvent->current_event_hash : str_repeat('0', 64);

                $payload = [
                    'loan_id'          => $lockedLoan->id,
                    'loan_number'      => $lockedLoan->loan_number,
                    'borrower_id'      => $lockedLoan->borrower_id,
                    'status'           => 'SETTLED',
                    'final_payment_ref'=> $reference,
                    'settled_at'       => CarbonImmutable::now()->toIso8601String(),
                ];

                $currentHash = hash('sha256', $prevHash . 'loan.settled' . json_encode($payload));

                OutboxEvent::create([
                    'aggregate_type'      => Loan::class,
                    'aggregate_id'        => $lockedLoan->id,
                    'event_name'          => 'loan.settled',
                    'payload'             => $payload,
                    'previous_event_hash' => $prevHash,
                    'current_event_hash'  => $currentHash,
                    'is_published'        => false,
                    'retry_count'         => 0,
                ]);
            }

            // Step 12: Record operational audit trail
            AuditTrail::create([
                'user_id'        => auth()->id(),
                'action'         => 'PAYMENT_WATERFALL_EXECUTED',
                'auditable_type' => Loan::class,
                'auditable_id'   => $lockedLoan->id,
                'old_values'     => [
                    'principal' => $currentPrincipal,
                    'interest'  => $currentInterest,
                    'fees'      => $currentFees,
                ],
                'new_values'     => [
                    'principal'   => $newPrincipal,
                    'interest'    => $newInterest,
                    'fees'        => $newFees,
                    'overpayment' => $overpaymentCredit,
                    'is_settled'  => $isSettled,
                ],
                'ip_address'     => request()->ip() ?? '127.0.0.1',
                'user_agent'     => request()->userAgent() ?? 'System/CLI',
            ]);

            Log::channel('daily')->info(sprintf(
                'Waterfall executed on Loan #%d: Received KES %s -> Fees: %s, Int: %s, Prin: %s, Overpay: %s [Ref: %s]',
                $lockedLoan->id,
                $amount,
                $allocatedFees,
                $allocatedInterest,
                $allocatedPrincipal,
                $overpaymentCredit,
                $reference
            ));

            return [
                'allocated_fees'      => $allocatedFees,
                'allocated_interest'  => $allocatedInterest,
                'allocated_principal' => $allocatedPrincipal,
                'overpayment_credit'  => $overpaymentCredit,
                'new_total_balance'   => $newTotalBalance,
                'is_settled'          => $isSettled,
            ];
        });
    }
}