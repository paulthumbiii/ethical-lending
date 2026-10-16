<?php

declare(strict_types=1);

use App\Exceptions\Fintech\MpesaAuthenticationException;
use App\Models\MpesaApiToken;
use App\Services\Financial\Mpesa\DarajaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Cache::forget('safaricom_daraja_access_token');

    config([
        'mpesa.env'             => 'sandbox',
        'mpesa.consumer_key'    => 'test_consumer_key_123',
        'mpesa.consumer_secret' => 'test_consumer_secret_456',
    ]);

    $this->client = app(DarajaClient::class);
});

it('successfully fetches and caches a fresh safaricom daraja access token', function (): void {
    Http::fake([
        '*/oauth/v1/generate*' => Http::response([
            'access_token' => 'SAF_TEST_BEARER_TOKEN_98765',
            'expires_in'   => '3599',
        ], 200),
    ]);

    $token = $this->client->getAccessToken();

    expect($token)->toBe('SAF_TEST_BEARER_TOKEN_98765');

    // Verify token is cached in Laravel Cache
    expect(Cache::get('safaricom_daraja_access_token'))->toBe('SAF_TEST_BEARER_TOKEN_98765');

    // Verify persisted record in mpesa_api_tokens table
    expect(MpesaApiToken::count())->toBe(1);
    expect(MpesaApiToken::first()->access_token)->toBe('SAF_TEST_BEARER_TOKEN_98765');

    Http::assertSentCount(1);
});

it('returns cached token on subsequent calls without making additional network requests', function (): void {
    Http::fake([
        '*/oauth/v1/generate*' => Http::response([
            'access_token' => 'SAF_TEST_CACHED_TOKEN_111',
            'expires_in'   => '3599',
        ], 200),
    ]);

    // First call: Makes HTTP request
    $token1 = $this->client->getAccessToken();

    // Second and third calls: Must return from cache!
    $token2 = $this->client->getAccessToken();
    $token3 = $this->client->getAccessToken();

    expect($token1)->toBe('SAF_TEST_CACHED_TOKEN_111')
        ->and($token2)->toBe('SAF_TEST_CACHED_TOKEN_111')
        ->and($token3)->toBe('SAF_TEST_CACHED_TOKEN_111');

    // Assert only ONE HTTP network call was made to Safaricom!
    Http::assertSentCount(1);
});

it('throws MpesaAuthenticationException when safaricom rejects credentials', function (): void {
    Http::fake([
        '*/oauth/v1/generate*' => Http::response([
            'errorCode'    => '400.002.02',
            'errorMessage' => 'Bad Request - Invalid Credentials',
        ], 400),
    ]);

    $this->client->getAccessToken();
})->throws(MpesaAuthenticationException::class);

it('correctly resolves sandbox and production base endpoints', function (): void {
    config(['mpesa.env' => 'sandbox']);
    expect($this->client->getBaseUrl())->toBe('https://sandbox.safaricom.co.ke');

    config(['mpesa.env' => 'production']);
    expect($this->client->getBaseUrl())->toBe('https://api.safaricom.co.ke');
});
