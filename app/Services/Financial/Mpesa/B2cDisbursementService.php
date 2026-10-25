<?php

declare(strict_types=1);

namespace App\Services\Financial\Mpesa;

use App\Enums\LoanStatus;
use App\Models\AuditTrail;
use App\Models\Loan;
use App\Models\LoanLedgerEntry;
use App\Models\MpesaB2cDisbursement;
use App\Models\OutboxEvent;
use App\Services\Financial\GeneralLedgerService;
use App\Services\Lending\LoanFeeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class B2cDisbursementService
{
    public function __construct(
        private readonly DarajaClient $darajaClient,
        private readonly SimSwapProtectionService $simSwapService,
        private readonly GeneralLedgerService $glService
    ) {}

    /**
     * Initiates an outbound M-Pesa B2C payment to the borrower's wallet.
     * Refuses to send twice for the same loan.
     */
    public function initiateDisbursement(Loan $loan, ?CarbonImmutable $mockSimSwapTimestamp = null): MpesaB2cDisbursement
    {
        return DB::transaction(function () use ($loan, $mockSimSwapTimestamp): MpesaB2cDisbursement {
            $lockedLoan = Loan::query()->where('id', $loan->id)->lockForUpdate()->firstOrFail();

            // DOUBLE-SEND GUARD: runs under the loan row lock.
            if ($lockedLoan->status !== LoanStatus::APPROVAL_HOLD) {
                throw new RuntimeException('This loan is not waiting for disbursement.');
            }

            $alreadySent = MpesaB2cDisbursement::query()
                ->where('loan_id', $lockedLoan->id)
                ->whereIn('status', ['initiated', 'successful'])
                ->exists();

            if ($alreadySent) {
                throw new RuntimeException('Money has already been sent, or is being sent, for this loan.');
            }

            $phoneNumber = (string) $lockedLoan->borrower->phone_number;

            // 1. Guard against SIM-Swap Fraud
            $this->simSwapService->assertPhoneIsSafeForDisbursement($phoneNumber, $mockSimSwapTimestamp);

            // 2. Prepare Daraja B2C Payload
            $token = $this->darajaClient->getAccessToken();
            $url = $this->darajaClient->getBaseUrl() . '/mpesa/b2c/v1/paymentrequest';
            $originatorConversationId = 'B2C-' . strtoupper(Str::random(12));

            $payload = [
                'InitiatorName'            => (string) config('mpesa.initiator_name', 'testapi'),
                'SecurityCredential'       => (string) config('mpesa.security_credential', 'testcredential'),
                'CommandID'                => 'BusinessPayment',
                'Amount'                   => (int) round((float) $lockedLoan->principal_disbursed),
                'PartyA'                   => (string) config('mpesa.b2c_shortcode', '600000'),
                'PartyB'                   => $phoneNumber,
                'Remarks'                  => 'Loan Disbursement ' . $lockedLoan->loan_number,
                'QueueTimeOutURL'          => url('/api/v1/mpesa/b2c-timeout'),
                'ResultURL'                => url('/api/v1/mpesa/b2c-result'),
                'Occasion'                 => 'Disbursement',
                'OriginatorConversationID' => $originatorConversationId,
            ];

            $response = Http::withToken($token)
                ->timeout((int) config('mpesa.http_timeout_seconds', 15))
                ->post($url, $payload);

            $responseData = $response->json() ?? [];
            $conversationId = (string) ($responseData['ConversationID'] ?? '');
            $responseCode = (string) ($responseData['ResponseCode'] ?? $response->status());
            $responseDescription = (string) ($responseData['ResponseDescription'] ?? $response->body());

            // 3. Persist initial B2C disbursement record
            $disbursement = MpesaB2cDisbursement::create([
                'loan_id'                    => $lockedLoan->id,
                'originator_conversation_id' => $originatorConversationId,
                'conversation_id'            => $conversationId !== '' ? $conversationId : null,
                'transaction_id'             => null,
                'recipient_phone'            => $phoneNumber,
                'amount'                     => $lockedLoan->principal_disbursed,
                'status'                     => $response->successful() ? 'initiated' : 'failed',
                'response_code'              => $responseCode,
                'response_description'       => $responseDescription,
                'disbursed_at'               => null,
            ]);

            AuditTrail::create([
                'user_id'        => auth()->id(),
                'action'         => 'MPESA_B2C_DISBURSEMENT_INITIATED',
                'auditable_type' => MpesaB2cDisbursement::class,
                'auditable_id'   => $disbursement->id,
                'old_values'     => null,
                'new_values'     => [
                    'amount'      => (string) $lockedLoan->principal_disbursed,
                    'phone'       => $phoneNumber,
                    'origin_conv' => $originatorConversationId,
                ],
                'ip_address'     => request()->ip() ?? '127.0.0.1',
                'user_agent'     => request()->userAgent() ?? 'System/CLI',
            ]);

            return $disbursement;
        });
    }

    /**
     * Processes the asynchronous Result callback from Safaricom B2C.
     * Safe to call more than once with the same result.
     *
     * @param array<string, mixed> $callbackData
     */
    public function processCallback(array $callbackData): MpesaB2cDisbursement
    {
        return DB::transaction(function () use ($callbackData): MpesaB2cDisbursement {
            $result = $callbackData['Result'] ?? $callbackData;
            $conversationId = (string) ($result['ConversationID'] ?? '');
            $originatorConversationId = (string) ($result['OriginatorConversationID'] ?? '');
            $resultCode = (int) ($result['ResultCode'] ?? -1);
            $resultDesc = (string) ($result['ResultDesc'] ?? 'No description');
            $transactionId = (string) ($result['TransactionID'] ?? 'TXN-' . Str::random(10));

            $disbursement = $this->findDisbursement($originatorConversationId, $conversationId);

            // IDEMPOTENCY: a finished disbursement is never posted again.
            if ($disbursement->status === 'successful') {
                return $disbursement;
            }

            $loan = Loan::query()->where('id', $disbursement->loan_id)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now();

            if ($resultCode === 0) {
                // SUCCESS: Funds delivered to borrower's phone wallet
                $disbursement->update([
                    'status'               => 'successful',
                    'transaction_id'       => $transactionId,
                    'response_code'        => (string) $resultCode,
                    'response_description' => $resultDesc,
                    'disbursed_at'         => $now,
                ]);

                $loan->update([
                    'status'       => LoanStatus::DISBURSED,
                    'disbursed_at' => $now,
                    'due_date'     => $now->addDays((int) $loan->tenure_days),
                ]);

                // Create initial immutable debit in Loan Ledger
                LoanLedgerEntry::create([
                    'loan_id'                   => $loan->id,
                    'entry_type'                => 'disbursement',
                    'debit'                     => $loan->principal_disbursed,
                    'credit'                    => '0.00',
                    'running_principal_balance' => $loan->principal_disbursed,
                    'running_interest_balance'  => '0.00',
                    'running_fees_balance'      => '0.00',
                    'total_running_balance'     => $loan->principal_disbursed,
                    'reference'                 => $transactionId,
                    'notes'                     => 'Disbursement confirmed via M-Pesa B2C Receipt ' . $transactionId,
                ]);

                // Double-Entry GL Posting: Debit Loans Receivable, Credit M-Pesa Settlement
                $this->glService->recordLoanDisbursementPosting($loan, (string) $loan->principal_disbursed);

                // Processing fees are added on top of the principal.
                $this->chargeFees($loan, $transactionId);

                // Cryptographically chained Outbox Event
                $prevEvent = OutboxEvent::query()->latest('id')->first();
                $prevHash = $prevEvent ? $prevEvent->current_event_hash : str_repeat('0', 64);

                $payload = [
                    'loan_id'         => $loan->id,
                    'loan_number'     => $loan->loan_number,
                    'disbursement_id' => $disbursement->id,
                    'transaction_id'  => $transactionId,
                    'amount'          => (string) $loan->principal_disbursed,
                    'phone'           => $disbursement->recipient_phone,
                    'disbursed_at'    => $now->toIso8601String(),
                ];

                $currentHash = hash('sha256', $prevHash . 'loan.disbursed' . json_encode($payload));

                OutboxEvent::create([
                    'aggregate_type'      => Loan::class,
                    'aggregate_id'        => $loan->id,
                    'event_name'          => 'loan.disbursed',
                    'payload'             => $payload,
                    'previous_event_hash' => $prevHash,
                    'current_event_hash'  => $currentHash,
                    'is_published'        => false,
                    'retry_count'         => 0,
                ]);
            } else {
                // FAILURE from Safaricom (e.g. Insufficient Utility Balance)
                $disbursement->update([
                    'status'               => 'failed',
                    'response_code'        => (string) $resultCode,
                    'response_description' => $resultDesc,
                ]);
            }

            return $disbursement;
        });
    }

    /**
     * Charges the loan's fees in their own savepoint. The money has already gone out
     * by the time this runs, so a fee problem must never undo the disbursement record.
     * If it fails, the fee is rolled back, the failure is audited and logged, and staff fix it by hand.
     */
    private function chargeFees(Loan $loan, string $receipt): void
    {
        try {
            DB::transaction(fn (): array => app(LoanFeeService::class)->charge($loan, $receipt));
        } catch (Throwable $e) {
            Log::channel('daily')->error('Fee charge failed after disbursement of loan ' . $loan->loan_number . ': ' . $e->getMessage());

            AuditTrail::create([
                'user_id'        => auth()->id(),
                'action'         => 'LOAN_FEE_CHARGE_FAILED',
                'auditable_type' => Loan::class,
                'auditable_id'   => $loan->id,
                'old_values'     => null,
                'new_values'     => [
                    'receipt' => $receipt,
                    'error'   => mb_substr($e->getMessage(), 0, 200),
                ],
                'ip_address'     => request()->ip() ?? '127.0.0.1',
                'user_agent'     => request()->userAgent() ?? 'System/CLI',
            ]);
        }
    }

    /**
     * Finds the disbursement a callback belongs to. An empty id never matches anything.
     */
    private function findDisbursement(string $originatorConversationId, string $conversationId): MpesaB2cDisbursement
    {
        if ($originatorConversationId === '' && $conversationId === '') {
            throw new RuntimeException('The callback has no conversation id.');
        }

        return MpesaB2cDisbursement::query()
            ->where(function ($query) use ($originatorConversationId, $conversationId): void {
                if ($originatorConversationId !== '') {
                    $query->where('originator_conversation_id', $originatorConversationId);
                }

                if ($conversationId !== '') {
                    $query->orWhere('conversation_id', $conversationId);
                }
            })
            ->lockForUpdate()
            ->firstOrFail();
    }
}