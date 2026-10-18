<?php

declare(strict_types=1);

namespace App\Services\Financial\Mpesa;

use App\Exceptions\Fintech\DuplicateWebhookException;
use App\Jobs\ProcessMpesaCallbackJob;
use App\Models\Loan;
use App\Models\MpesaC2bCallback;
use App\Models\MpesaStkRequest;
use App\Services\Financial\GeneralLedgerService;
use App\Services\Financial\PaymentWaterfallService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class MpesaWebhookService
{
    public function __construct(
        private readonly PaymentWaterfallService $waterfallService,
        private readonly GeneralLedgerService $glService
    ) {}

    /**
     * Ingests incoming C2B Paybill Confirmation webhook from Safaricom.
     *
     * @param array<string, mixed> $payload
     */
    public function ingestC2bConfirmation(array $payload): MpesaC2bCallback
    {
        $transId = (string) ($payload['TransID'] ?? '');
        $amount = (string) ($payload['TransAmount'] ?? '0.00');
        $billRef = trim((string) ($payload['BillRefNumber'] ?? ''));
        $msisdn = (string) ($payload['MSISDN'] ?? '');

        if ($transId === '') {
            throw new \InvalidArgumentException('TransID missing from Safaricom C2B payload.');
        }

        // Idempotency Check: Prevent duplicate webhook processing
        $existing = MpesaC2bCallback::query()->where('trans_id', $transId)->first();
        if ($existing !== null) {
            throw new DuplicateWebhookException($transId, 'SAFARICOM_C2B');
        }

        try {
            $callback = MpesaC2bCallback::create([
                'transaction_type'    => (string) ($payload['TransactionType'] ?? 'Pay Utility'),
                'trans_id'            => $transId,
                'trans_time'          => (string) ($payload['TransTime'] ?? CarbonImmutable::now()->format('YmdHis')),
                'trans_amount'        => $amount,
                'business_short_code' => (string) ($payload['BusinessShortCode'] ?? config('mpesa.shortcode')),
                'bill_ref_number'     => $billRef,
                'msisdn'              => $msisdn,
                'first_name'          => (string) ($payload['FirstName'] ?? null),
                'is_reconciled'       => false,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two identical callbacks arrived at the same instant: the database unique index on
            // trans_id let only one in. Treat the loser exactly like a normal duplicate.
            throw new DuplicateWebhookException($transId, 'SAFARICOM_C2B');
        }

        // Dispatch background job for high-concurrency ledger crediting
        ProcessMpesaCallbackJob::dispatch('C2B', $callback->id);

        return $callback;
    }

    /**
     * Ingests incoming Lipa na M-Pesa STK Push Callback from Safaricom.
     *
     * @param array<string, mixed> $payload
     */
    public function ingestStkCallback(array $payload): MpesaStkRequest
    {
        $stkCallback = $payload['Body']['stkCallback'] ?? [];
        $checkoutRequestId = (string) ($stkCallback['CheckoutRequestID'] ?? '');
        $resultCode = (int) ($stkCallback['ResultCode'] ?? -1);
        $resultDesc = (string) ($stkCallback['ResultDesc'] ?? 'No description');

        $stkRequest = MpesaStkRequest::query()
            ->where('checkout_request_id', $checkoutRequestId)
            ->firstOrFail();

        // Check if already processed
        if ($stkRequest->status === 'successful') {
            throw new DuplicateWebhookException($checkoutRequestId, 'SAFARICOM_STK');
        }

        $metadataItems = $stkCallback['CallbackMetadata']['Item'] ?? [];
        $receiptNumber = null;
        $amount = (string) $stkRequest->amount;

        foreach ($metadataItems as $item) {
            if (($item['Name'] ?? '') === 'MpesaReceiptNumber') {
                $receiptNumber = (string) $item['Value'];
            }
            if (($item['Name'] ?? '') === 'Amount') {
                $amount = number_format((float) $item['Value'], 2, '.', '');
            }
        }

        if ($resultCode === 0 && $receiptNumber !== null) {
            $stkRequest->update([
                'status'               => 'successful',
                'mpesa_receipt_number' => $receiptNumber,
                'result_code'          => $resultCode,
                'result_desc'          => $resultDesc,
                'amount'               => $amount,
            ]);

            // Dispatch background job for waterfall execution
            ProcessMpesaCallbackJob::dispatch('STK', $stkRequest->id);
        } else {
            $stkRequest->update([
                'status'      => 'failed',
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
            ]);
        }

        return $stkRequest;
    }

    /**
     * Asynchronously executes payment waterfall and double-entry postings for an ingested callback.
     */
    public function processRepayment(string $sourceType, int $recordId): void
    {
        DB::transaction(function () use ($sourceType, $recordId): void {
            if ($sourceType === 'C2B') {
                $callback = MpesaC2bCallback::query()->where('id', $recordId)->lockForUpdate()->firstOrFail();
                if ($callback->is_reconciled) {
                    return; // Already reconciled
                }

                $loan = $this->resolveLoanFromReference($callback->bill_ref_number, $callback->msisdn);
                if ($loan === null) {
                    Log::channel('daily')->warning(sprintf(
                        'Unmatched C2B Payment: No active loan found for BillRef [%s] or MSISDN [%s] [TransID: %s]',
                        $callback->bill_ref_number,
                        $callback->msisdn,
                        $callback->trans_id
                    ));
                    return;
                }

                $amount = (string) $callback->trans_amount;
                $reference = (string) $callback->trans_id;

                // 1. Execute Legal Payment Waterfall
                $waterfall = $this->waterfallService->executeWaterfall(
                    loan: $loan,
                    amount: $amount,
                    reference: $reference,
                    source: 'MPESA_PAYBILL_C2B'
                );

                // 2. Post Balanced Double-Entry General Ledger Entry
                $this->glService->recordRepaymentPosting(
                    loan: $loan,
                    feesPaid: $waterfall['allocated_fees'],
                    interestPaid: $waterfall['allocated_interest'],
                    principalPaid: $waterfall['allocated_principal'],
                    overpayment: $waterfall['overpayment_credit'],
                    reference: $reference
                );

                $callback->update(['is_reconciled' => true]);
            } elseif ($sourceType === 'STK') {
                $stk = MpesaStkRequest::query()->where('id', $recordId)->lockForUpdate()->firstOrFail();
                if ($stk->status !== 'successful' || $stk->loan_id === null) {
                    return;
                }

                $reference = (string) $stk->mpesa_receipt_number;

                // Idempotency guard: the STK row is locked above, so a retried or duplicated job
                // waits here, then sees the receipt already credited and stops. Without this,
                // the same receipt could be credited twice.
                $alreadyCredited = DB::table('payment_waterfall_logs')
                    ->where('loan_id', $stk->loan_id)
                    ->where('payment_reference', $reference)
                    ->exists();

                if ($alreadyCredited) {
                    Log::channel('daily')->info(sprintf(
                        'STK repayment skipped: receipt [%s] already credited to loan #%d',
                        $reference,
                        $stk->loan_id
                    ));
                    return;
                }

                $loan = Loan::query()->where('id', $stk->loan_id)->firstOrFail();
                $amount = (string) $stk->amount;

                // 1. Execute Legal Payment Waterfall
                $waterfall = $this->waterfallService->executeWaterfall(
                    loan: $loan,
                    amount: $amount,
                    reference: $reference,
                    source: 'MPESA_STK_PUSH'
                );

                // 2. Post Balanced Double-Entry General Ledger Entry
                $this->glService->recordRepaymentPosting(
                    loan: $loan,
                    feesPaid: $waterfall['allocated_fees'],
                    interestPaid: $waterfall['allocated_interest'],
                    principalPaid: $waterfall['allocated_principal'],
                    overpayment: $waterfall['overpayment_credit'],
                    reference: $reference
                );
            }
        });
    }

    /**
     * Resolves active loan by Loan Number, Application Number, or borrower's registered phone.
     */
    private function resolveLoanFromReference(string $reference, string $phone): ?Loan
    {
        // 1. Match exact loan number (e.g. LN-B2C-01)
        $loan = Loan::query()->where('loan_number', $reference)->first();
        if ($loan !== null) {
            return $loan;
        }

        // 2. Match active borrower loan by phone
        return Loan::query()
            ->whereHas('borrower', fn ($q) => $q->where('phone_number', $phone))
            ->whereIn('status', ['disbursed', 'defaulted', 'watch'])
            ->latest('id')
            ->first();
    }
}