<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Safaricom Daraja Environment
    |--------------------------------------------------------------------------
    | Supported: 'sandbox', 'production'
    */
    'env' => env('MPESA_ENV', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | API Credentials
    |--------------------------------------------------------------------------
    */
    'consumer_key'    => env('MPESA_CONSUMER_KEY', ''),
    'consumer_secret' => env('MPESA_CONSUMER_SECRET', ''),
    'passkey'         => env('MPESA_PASSKEY', 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919'),

    /*
    |--------------------------------------------------------------------------
    | Business Shortcodes
    |--------------------------------------------------------------------------
    */
    'shortcode'     => env('MPESA_SHORTCODE', '174379'),
    'b2c_shortcode' => env('MPESA_B2C_SHORTCODE', '600000'),

    /*
    |--------------------------------------------------------------------------
    | Gateway Base Endpoints
    |--------------------------------------------------------------------------
    */
    'endpoints' => [
        'sandbox'    => 'https://sandbox.safaricom.co.ke',
        'production' => 'https://api.safaricom.co.ke',
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Cache Settings
    |--------------------------------------------------------------------------
    | Cache safety buffer in seconds to refresh before actual expiration.
    */
    'token_cache_buffer_seconds' => 120,
    'http_timeout_seconds'       => 15,
];