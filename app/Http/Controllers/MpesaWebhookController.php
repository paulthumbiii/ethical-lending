<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\Fintech\DuplicateWebhookException;
use App\Services\Financial\Mpesa\B2cDisbursementService;
use App\Services\Financial\Mpesa\MpesaWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MpesaWebhookController extends Controller
{
    public function __construct(
        private readonly MpesaWebhookService $webhookService,
        private readonly B2cDisbursementService $b2cService
    ) {}

    /**
     * C2B Validation endpoint called by Safaricom prior to confirming Paybill payments.
     */
    public function c2bValidation(Request $request): JsonResponse
    {
        // Accept all payments by default; account reconciliation happens on confirmation
        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted',
        ]);
    }

    /**
     * C2B Confirmation endpoint called by Safaricom when cash is received.
     */
    public function c2bConfirmation(Request $request): JsonResponse
    {
        try {
            $payload = $request->all();
            $this->webhookService->ingestC2bConfirmation($payload);

            return response()->json([
                'ResultCode' => 0,
                'ResultDesc' => 'Confirmation received successfully',
            ]);
        } catch (DuplicateWebhookException $e) {
            Log::channel('daily')->info($e->getMessage());

            // Acknowledge duplicate safely to Safaricom so they stop retrying
            return response()->json([
                'ResultCode' => 0,
                'ResultDesc' => 'Duplicate acknowledged',
            ]);
        } catch (Throwable $e) {
            Log::channel('daily')->error('Error processing C2B Confirmation webhook: ' . $e->getMessage());

            // Still return 0 to Safaricom to prevent endless retry storms
            return response()->json([
                'ResultCode' => 0,
                'ResultDesc' => 'Ingested with internal logged error',
            ]);
        }
    }

    /**
     * STK Push Callback endpoint called by Safaricom after Lipa na M-Pesa prompt.
     */
    public function stkCallback(Request $request): JsonResponse
    {
        try {
            $payload = $request->all();
            $this->webhookService->ingestStkCallback($payload);

            return response()->json([
                'ResultCode' => 0,
                'ResultDesc' => 'STK callback processed',
            ]);
        } catch (DuplicateWebhookException $e) {
            Log::channel('daily')->info($e->getMessage());

            return response()->json([
                'ResultCode' => 0,
                'ResultDesc' => 'Duplicate STK acknowledged',
            ]);
        } catch (Throwable $e) {
            Log::channel('daily')->error('Error processing STK Callback: ' . $e->getMessage());

            return response()->json([
                'ResultCode' => 0,
                'ResultDesc' => 'Error logged internally',
            ]);
        }
    }

    /**
     * B2C Result callback: Safaricom reports whether a loan payout succeeded.
     */
    public function b2cResult(Request $request): JsonResponse
    {
        try {
            $this->b2cService->processCallback($request->all());

            return response()->json([
                'ResultCode' => 0,
                'ResultDesc' => 'B2C result processed',
            ]);
        } catch (Throwable $e) {
            Log::channel('daily')->error('Error processing B2C result: ' . $e->getMessage());

            return response()->json([
                'ResultCode' => 0,
                'ResultDesc' => 'Error logged internally',
            ]);
        }
    }

    /**
     * B2C Timeout callback: the payout outcome is unknown. We only log it.
     * The disbursement stays "initiated" so the double-send guard keeps blocking
     * a second payout until staff have checked what really happened.
     */
    public function b2cTimeout(Request $request): JsonResponse
    {
        Log::channel('daily')->warning('M-Pesa B2C timeout received. Check this payout by hand.', [
            'originator_conversation_id' => $request->input('Result.OriginatorConversationID', $request->input('OriginatorConversationID')),
        ]);

        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => 'Timeout noted',
        ]);
    }
}