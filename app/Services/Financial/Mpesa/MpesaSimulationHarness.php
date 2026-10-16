<?php

declare(strict_types=1);

namespace App\Services\Financial\Mpesa;

use App\Models\Loan;
use App\Models\MpesaStkRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class MpesaSimulationHarness
{
    public function __construct(private readonly MpesaWebhookService $webhookService) {}

    /**
     * Simulates an inbound C2B Paybill repayment from a borrower.
     *
     * @return array{
     *     status: string,
     *     trans_id: string,
     *     amount: string,
     *     loan_number: string,
     *     payload: array<string, mixed>
     * }
     */
    public function simulateC2bPayment(
        Loan $loan,
        string $amount,
        ?string $transId = null,
        ?string $phone = null
    ): array {
        $transId = $transId ?? 'SIM' . strtoupper(Str::random(8));
        $phone = $phone ?? (string) $loan->borrower->phone_number;

        $payload = [
            'TransactionType'   => 'Pay Utility',
            'TransID'           => $transId,
            'TransTime'         => CarbonImmutable::now()->format('YmdHis'),
            'TransAmount'       => number_format((float) $amount, 2, '.', ''),
            'BusinessShortCode' => (string) config('mpesa.shortcode', '174379'),
            'BillRefNumber'     => (string) $loan->loan_number,
            'InvoiceNumber'     => 'INV-' . strtoupper(Str::random(6)),
            'OrgAccountBalance' => '500000.00',
            'ThirdPartyTransID' => null,
            'MSISDN'            => $phone,
            'FirstName'         => (string) ($loan->borrower->full_name ?? 'Simulated Borrower'),
        ];

        // Ingest into webhook pipeline (automatically dispatches queue job to reconcile)
        $this->webhookService->ingestC2bConfirmation($payload);

        return [
            'status'      => 'processed',
            'trans_id'    => $transId,
            'amount'      => $amount,
            'loan_number' => $loan->loan_number,
            'payload'     => $payload,
        ];
    }

    /**
     * Simulates a successful Lipa na M-Pesa STK Push repayment.
     *
     * @return array{
     *     checkout_request_id: string,
     *     receipt_number: string,
     *     amount: string,
     *     status: string
     * }
     */
    public function simulateStkSuccess(
        Loan $loan,
        string $amount,
        ?string $receiptNumber = null
    ): array {
        $checkoutRequestId = 'ws_SIM_' . strtoupper(Str::random(12));
        $merchantRequestId = 'MERCH_' . strtoupper(Str::random(8));
        $receiptNumber = $receiptNumber ?? 'QHD' . strtoupper(Str::random(7));
        $phone = (string) $loan->borrower->phone_number;

        // 1. Create simulated pending STK request
        $stk = MpesaStkRequest::create([
            'loan_id'              => $loan->id,
            'merchant_request_id'  => $merchantRequestId,
            'checkout_request_id'  => $checkoutRequestId,
            'phone_number'         => $phone,
            'amount'               => $amount,
            'status'               => 'pending',
        ]);

        // 2. Synthesize authentic Safaricom Callback Metadata
        $callbackPayload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => $merchantRequestId,
                    'CheckoutRequestID' => $checkoutRequestId,
                    'ResultCode'        => 0,
                    'ResultDesc'        => 'The service request is processed successfully.',
                    'CallbackMetadata'  => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => (float) $amount],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => $receiptNumber],
                            ['Name' => 'Balance', 'Value' => 0.00],
                            ['Name' => 'TransactionDate', 'Value' => (int) CarbonImmutable::now()->format('YmdHis')],
                            ['Name' => 'PhoneNumber', 'Value' => (int) $phone],
                        ],
                    ],
                ],
            ],
        ];

        // 3. Ingest into webhook pipeline
        $this->webhookService->ingestStkCallback($callbackPayload);

        return [
            'checkout_request_id' => $checkoutRequestId,
            'receipt_number'      => $receiptNumber,
            'amount'              => $amount,
            'status'              => 'successful',
        ];
    }

    /**
     * Simulates a user-cancelled STK Push prompt (PIN cancelled by borrower).
     */
    public function simulateStkCancelled(Loan $loan, string $amount): MpesaStkRequest
    {
        $checkoutRequestId = 'ws_CANCEL_' . strtoupper(Str::random(12));
        $merchantRequestId = 'MERCH_' . strtoupper(Str::random(8));

        MpesaStkRequest::create([
            'loan_id'              => $loan->id,
            'merchant_request_id'  => $merchantRequestId,
            'checkout_request_id'  => $checkoutRequestId,
            'phone_number'         => (string) $loan->borrower->phone_number,
            'amount'               => $amount,
            'status'               => 'pending',
        ]);

        $cancelPayload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => $merchantRequestId,
                    'CheckoutRequestID' => $checkoutRequestId,
                    'ResultCode'        => 1032,
                    'ResultDesc'        => 'Request cancelled by user',
                ],
            ],
        ];

        return $this->webhookService->ingestStkCallback($cancelPayload);
    }
}