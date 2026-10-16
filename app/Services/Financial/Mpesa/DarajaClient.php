<?php

declare(strict_types=1);

namespace App\Services\Financial\Mpesa;

use App\Exceptions\Fintech\MpesaAuthenticationException;
use App\Models\MpesaApiToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class DarajaClient
{
    private const CACHE_KEY = 'safaricom_daraja_access_token';

    /**
     * Retrieves an active Safaricom Daraja Bearer access token, utilizing cache.
     */
    public function getAccessToken(): string
    {
        // 1. Return from memory/Redis cache if still valid
        $cachedToken = Cache::get(self::CACHE_KEY);
        if (is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        // 2. Fetch fresh token from Safaricom
        return $this->fetchFreshAccessToken();
    }

    /**
     * Resolves the active base URL based on config/environment.
     */
    public function getBaseUrl(): string
    {
        $env = config('mpesa.env', 'sandbox');
        return config("mpesa.endpoints.{$env}", 'https://sandbox.safaricom.co.ke');
    }

    /**
     * Requests a fresh OAuth token from Safaricom and caches it.
     *
     * @throws MpesaAuthenticationException
     */
    public function fetchFreshAccessToken(): string
    {
        $consumerKey = (string) config('mpesa.consumer_key');
        $consumerSecret = (string) config('mpesa.consumer_secret');
        $timeout = (int) config('mpesa.http_timeout_seconds', 15);

        $url = $this->getBaseUrl() . '/oauth/v1/generate?grant_type=client_credentials';

        $response = Http::withBasicAuth($consumerKey, $consumerSecret)
            ->timeout($timeout)
            ->acceptJson()
            ->get($url);

        if (!$response->successful()) {
            Log::channel('daily')->error('Safaricom Daraja OAuth Token Request Failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            throw new MpesaAuthenticationException(
                statusCode: $response->status(),
                responseBody: $response->body()
            );
        }

        $data = $response->json();
        $token = (string) ($data['access_token'] ?? '');
        $expiresIn = (int) ($data['expires_in'] ?? 3599);

        if ($token === '') {
            throw new MpesaAuthenticationException(
                statusCode: 500,
                responseBody: 'Safaricom returned 200 OK but access_token was missing from JSON payload.'
            );
        }

        // Apply 2-minute safety buffer before token expiration
        $buffer = (int) config('mpesa.token_cache_buffer_seconds', 120);
        $ttlSeconds = max(60, $expiresIn - $buffer);

        // Store in Laravel Cache
        Cache::put(self::CACHE_KEY, $token, CarbonImmutable::now()->addSeconds($ttlSeconds));

        // Persist to database table for auditing token lifecycles
        MpesaApiToken::create([
            'access_token' => $token,
            'expires_in'   => $expiresIn,
            'expires_at'   => CarbonImmutable::now()->addSeconds($expiresIn),
        ]);

        Log::channel('daily')->info('Safaricom Daraja access token successfully refreshed and cached', [
            'expires_in'  => $expiresIn,
            'ttl_seconds' => $ttlSeconds,
        ]);

        return $token;
    }
}