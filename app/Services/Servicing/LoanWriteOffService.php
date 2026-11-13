<?php

declare(strict_types=1);

namespace App\Services\Servicing;

use App\Enums\LoanStatus;
use App\Models\AuditTrail;
use App\Models\CollectionCase;
use App\Models\Loan;
use App\Models\LoanLedgerEntry;
use App\Models\LoanWriteOff;
use App\Models\MakerCheckerRequest;
use App\Services\Financial\GeneralLedgerService;
use App\Services\Financial\LoanLedgerService;
use App\Services\Governance\MakerCheckerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class LoanWriteOffService
{
    public function __construct(
        private readonly MakerCheckerService $makerChecker,
        private readonly LoanLedgerService $loanLedger,
        private readonly GeneralLedgerService $generalLedger
    ) {
    }

    /**
     * Proposes a loan write-off, creating a pending MakerCheckerRequest.
     */
    public function propose(Loan $loan, int $makerUserId, string $reason): MakerCheckerRequest
    {
        if ($loan->status !== LoanStatus::DEFAULTED) {
            throw new RuntimeException("Only defaulted loans can be proposed for write-off. Loan #{$loan->loan_number} is {$loan->status->value}.");
        }

        $existing = MakerCheckerRequest::query()
            ->where('action_type', 'WRITE_OFF_LOAN')
            ->where('target_entity_type', Loan::class)
            ->where('target_entity_id', $loan->id)
            ->where('status', 'pending')
            ->exists();

        if ($existing) {
            throw new RuntimeException("A write-off request is already pending review for Loan #{$loan->loan_number}.");
        }

        $latest = LoanLedgerEntry::query()->where('loan_id', $loan->id)->latest('id')->first();
        $total = $latest ? (string) $latest->total_running_balance : '0.00';

        if (bccomp($total, '0.00', 2) <= 0) {
            throw new RuntimeException("Loan #{$loan->loan_number} has no outstanding balance to write off.");
        }

        $principal = $latest ? (string) $latest->running_principal_balance : '0.00';
        $interest  = $latest ? (string) $latest->running_interest_balance : '0.00';
        $fees      = $latest ? (string) $latest->running_fees_balance : '0.00';

        $payload = [
            'loan_number' => $loan->loan_number,
            'reason'      => $reason,
            'principal'   => $principal,
            'interest'    => $interest,
            'fees'        => $fees,
            'total'       => $total,
        ];

        $request = $this->makerChecker->createPendingRequest(
            $makerUserId,
            'WRITE_OFF_LOAN',
            Loan::class,
            $loan->id,
            $payload
        );

        AuditTrail::create([
            'user_id'        => $makerUserId,
            'action'         => 'LOAN_WRITE_OFF_REQUESTED',
            'auditable_type' => Loan::class,
            'auditable_id'   => $loan->id,
            'old_values'     => ['status' => $loan->status->value, 'balance' => $total],
            'new_values'     => ['maker_checker_request_id' => $request->id, 'reason' => $reason],
            'ip_address'     => request()->ip() ?? '127.0.0.1',
            'user_agent'     => request()->userAgent() ?? 'System/CLI',
        ]);

        return $request;
    }

    /**
     * Executes the authorized write-off: credits off the debt, updates loan status,
     * creates the LoanWriteOff record, closes any open collection case, and posts to GL.
     */
    public function execute(Loan $loan, int $checkerUserId, ?string $checkerNotes = null): LoanWriteOff
    {
        return DB::transaction(function () use ($loan, $checkerUserId, $checkerNotes): LoanWriteOff {
            /** @var Loan $lockedLoan */
            $lockedLoan = Loan::query()->where('id', $loan->id)->lockForUpdate()->firstOrFail();

            if ($lockedLoan->status === LoanStatus::WRITTEN_OFF) {
                throw new RuntimeException("Loan #{$lockedLoan->loan_number} is already written off.");
            }

            if ($lockedLoan->status === LoanStatus::SETTLED) {
                throw new RuntimeException("Loan #{$lockedLoan->loan_number} is already settled.");
            }

            $latest = LoanLedgerEntry::query()->where('loan_id', $lockedLoan->id)->latest('id')->first();
            $principal = $latest ? (string) $latest->running_principal_balance : '0.00';
            $interest  = $latest ? (string) $latest->running_interest_balance : '0.00';
            $fees      = $latest ? (string) $latest->running_fees_balance : '0.00';
            $total     = $latest ? (string) $latest->total_running_balance : '0.00';

            // Post ledger write-off credits to bring running balances to 0.00
            $ref = 'WRITEOFF-' . $lockedLoan->loan_number;

            if (bccomp($principal, '0.00', 2) > 0) {
                $this->loanLedger->recordEntry(
                    $lockedLoan->id,
                    'WRITE_OFF_PRINCIPAL_CREDIT',
                    '0.00',
                    $principal,
                    $ref,
                    'Principal written off'
                );
            }

            if (bccomp($interest, '0.00', 2) > 0) {
                $this->loanLedger->recordEntry(
                    $lockedLoan->id,
                    'WRITE_OFF_INTEREST_CREDIT',
                    '0.00',
                    $interest,
                    $ref,
                    'Interest written off'
                );
            }

            if (bccomp($fees, '0.00', 2) > 0) {
                $this->loanLedger->recordEntry(
                    $lockedLoan->id,
                    'WRITE_OFF_FEES_CREDIT',
                    '0.00',
                    $fees,
                    $ref,
                    'Fees written off'
                );
            }

            // Create immutable LoanWriteOff record
            $writeOff = LoanWriteOff::create([
                'loan_id'                         => $lockedLoan->id,
                'written_off_principal'           => $principal,
                'written_off_interest'            => $interest,
                'written_off_fees'                => $fees,
                'total_written_off'               => $total,
                'write_off_reason'                => $checkerNotes ?: 'Approved via maker-checker',
                'approved_by_user_id'             => $checkerUserId,
                'written_off_at'                  => now(),
                'post_write_off_recovered_amount' => '0.00',
            ]);

            // Flip loan status to WRITTEN_OFF
            $oldStatus = $lockedLoan->status->value;
            $lockedLoan->update(['status' => LoanStatus::WRITTEN_OFF]);

            // Close collection case if open
            if ($lockedLoan->collectionCase && $lockedLoan->collectionCase->case_status === 'open') {
                $lockedLoan->collectionCase->update(['case_status' => 'closed']);
                AuditTrail::create([
                    'user_id'        => $checkerUserId,
                    'action'         => 'COLLECTION_CASE_CLOSED',
                    'auditable_type' => CollectionCase::class,
                    'auditable_id'   => $lockedLoan->collectionCase->id,
                    'old_values'     => ['case_status' => 'open'],
                    'new_values'     => ['case_status' => 'closed', 'reason' => 'Loan written off'],
                    'ip_address'     => request()->ip() ?? '127.0.0.1',
                    'user_agent'     => request()->userAgent() ?? 'System/CLI',
                ]);
            }

            // Post double-entry General Ledger entry for bad debt expense
            try {
                if (bccomp($total, '0.00', 2) > 0) {
                    $this->generalLedger->recordLoanWriteOffPosting($lockedLoan, $total);
                }
            } catch (\Throwable $e) {
                Log::channel('fintech')->warning("GL posting skipped during write-off of Loan #{$lockedLoan->loan_number}: " . $e->getMessage());
            }

            AuditTrail::create([
                'user_id'        => $checkerUserId,
                'action'         => 'LOAN_WRITTEN_OFF',
                'auditable_type' => Loan::class,
                'auditable_id'   => $lockedLoan->id,
                'old_values'     => ['status' => $oldStatus, 'balance' => $total],
                'new_values'     => [
                    'status'            => LoanStatus::WRITTEN_OFF->value,
                    'loan_write_off_id' => $writeOff->id,
                    'total_written_off' => $total,
                ],
                'ip_address'     => request()->ip() ?? '127.0.0.1',
                'user_agent'     => request()->userAgent() ?? 'System/CLI',
            ]);

            Log::channel('fintech')->info("Loan #{$lockedLoan->loan_number} successfully written off for KES {$total} by Checker #{$checkerUserId}");

            return $writeOff;
        });
    }
}