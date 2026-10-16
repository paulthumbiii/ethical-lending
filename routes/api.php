<?php

use App\Http\Controllers\MpesaWebhookController;
use App\Http\Controllers\UssdController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Safaricom M-Pesa Webhooks (/api/v1/mpesa/*)
    Route::prefix('mpesa')->group(function () {
        Route::post('/c2b-validation', [MpesaWebhookController::class, 'c2bValidation']);
        Route::post('/c2b-confirmation', [MpesaWebhookController::class, 'c2bConfirmation']);
        Route::post('/stk-callback', [MpesaWebhookController::class, 'stkCallback']);
    });

    // Africa's Talking USSD Gateway (/api/v1/ussd)
    Route::post('/ussd', [UssdController::class, 'handleUssd']);
});