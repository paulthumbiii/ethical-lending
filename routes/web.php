<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ContractVerificationController;

Route::get('/verify/contract/{code}', [ContractVerificationController::class, 'verify'])->name('contract.verify');
Route::get('/', function () {
    return view('welcome');
});
