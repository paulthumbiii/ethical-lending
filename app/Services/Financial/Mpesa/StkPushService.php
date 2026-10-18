<?php

declare(strict_types=1);

namespace App\Services\Financial\Mpesa;

use App\Exceptions\Fintech\MpesaAuthenticationException;
use App\Exceptions\Fintech\StkPushFailedException;
use App\Models\Loan;
use App\Models\MpesaStkRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class StkPushService
{
    public const MIN_AMOUNT = 1;

    public const MAX_AMOUNT = 250000;

    private const DUPLICATE_WINDOW_MINUTES = 2;

    public function __construct(private readonly DarajaClient $daraja)
    {
    }

    /**
     * Sends an STK Push to the borrower's registered phone and records it as pending.
     *
     * @throws \DomainException        when a prompt was sent a moment ago
     * @throws StkPushFailedException  when the prompt could not be sent
     */
    public function initiate(Loan $loan, int $amount): MpesaStkRequest
    {
        if ($amount < self::MIN_AMOUNT || $amount > self::MAX_AMOUNT) {
            throw new \InvalidArgumentException('Amount must be between 1 and 250,000 shillings.');
        }

        $phone = (string) $loan->borrower->phone_number;
        if (preg_match('/^254[17]\d{8}$/', $phone) !== 1) {
            throw new StkPushFailedException('The phone number on your account is not a valid M-Pesa number. Please contact support.');
        }

        $callbackUrl = (string) config('mpesa.stk_callback_url');
        if ($callbackUrl === '') {
            throw new StkPushFailedException('Payment prompts are not switched on yet. Please try again later.');
        }

        $recentPrompt = MpesaStkRequest::query()
            ->where('loan_id', $loan->id)
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinutes(self::DUPLICATE_WINDOW_MINUTES))
            ->exists();

        if ($recentPrompt) {
            throw new \DomainException('A payment prompt was just sent to your phone. Finish it there, or wait two minutes and try again.');
        }

        $timestamp = CarbonImmutable::now('Africa/Nairobi')->format('YmdHis');
        $shortcode = (string) config('mpesa.shortcode');
        $password = base64_encode($shortcode . (string) config('mpesa.passkey') . $timestamp);

        try {
            $response = Http::withToken($this->daraja->getAccessToken())
                ->timeout((int) config('mpesa.http_timeout_seconds', 15))
                ->acceptJson()
                ->asJson()
                ->post($this->daraja->getBaseUrl() . '/mpesa/stkpush/v1/processrequest', [
                    'BusinessShortCode' => $shortcode,
                    'Password'          => $password,
                    'Timestamp'         => $timestamp,
                    'TransactionType'   => 'CustomerPayBillOnline',
                    'Amount'            => $amount,
                    'PartyA'            => $phone,
                    'PartyB'            => $shortcode,
                    'PhoneNumber'       => $phone,
                    'CallBackURL'       => $callbackUrl,
                    'AccountReference'  => Str::limit((string) $loan->loan_number, 12, ''),
                    'TransactionDesc'   => 'Loan repay',
                ]);
        } catch (ConnectionException | MpesaAuthenticationException $e) {
            Log::channel('daily')->error('STK Push could not reach Safaricom', ['loan_id' => $loan->id]);

            throw new StkPushFailedException('We could not reach M-Pesa. Please try again in a moment.', 0, $e);
        }

        $checkoutId = (string) $response->json('CheckoutRequestID', '');

        if (! $response->successful() || (string) $response->json('ResponseCode') !== '0' || $checkoutId === '') {
            Log::channel('daily')->error('STK Push rejected by Safaricom', [
                'loan_id' => $loan->id,
                'status'  => $response->status(),
                'body'    => $response->body(),
            ]);

            throw new StkPushFailedException('M-Pesa could not send the payment prompt. Please try again in a moment.');
        }

        return MpesaStkRequest::create([
            'loan_id'             => $loan->id,
            'merchant_request_id' => (string) $response->json('MerchantRequestID', ''),
            'checkout_request_id' => $checkoutId,
            'phone_number'        => $phone,
            'amount'              => $amount . '.00',
            'status'              => 'pending',
        ]);
    }
}