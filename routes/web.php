<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Borrower\ApplicationController;
use App\Http\Controllers\Borrower\PaymentController;
use App\Http\Controllers\Borrower\PortalController;
use App\Http\Controllers\Borrower\RestructuringController;
use App\Http\Controllers\ContractVerificationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Lender\CollectionsWorkbenchController;
use App\Http\Controllers\Lender\MakerCheckerController;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\RoleMiddleware;

Route::get('/verify/contract/{code}', [ContractVerificationController::class, 'verify'])->name('contract.verify');

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1')->name('login.store');

    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:5,1')->name('register.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['auth', RoleMiddleware::class . ':' . UserRole::staffPipe()])
    ->prefix('lender')
    ->name('lender.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'lender'])->name('dashboard');

        // Dual-control review desk: approvers only (lender_admin, finance_officer).
        Route::middleware(RoleMiddleware::class . ':' . UserRole::approversPipe())
            ->prefix('maker-checker')
            ->name('maker-checker.')
            ->group(function () {
                Route::get('/', [MakerCheckerController::class, 'index'])->name('index');
                Route::post('/{makerCheckerRequest}/approve', [MakerCheckerController::class, 'approve'])->name('approve');
                Route::post('/{makerCheckerRequest}/reject', [MakerCheckerController::class, 'reject'])->name('reject');
            });

        // Collections and in-duplum workbench: read-only (GET only, by design).
        Route::middleware(RoleMiddleware::class . ':' . UserRole::collectionsViewersPipe())
            ->prefix('collections')
            ->name('collections.')
            ->group(function () {
                Route::get('/', [CollectionsWorkbenchController::class, 'index'])->name('index');
            });
    });

Route::middleware(['auth', RoleMiddleware::class . ':' . UserRole::Borrower->value])
    ->prefix('borrower')
    ->name('borrower.')
    ->group(function () {
        Route::get('/dashboard', [PortalController::class, 'show'])->name('dashboard');
        Route::get('/apply', [ApplicationController::class, 'create'])->name('apply.create');
        Route::post('/apply', [ApplicationController::class, 'store'])->middleware('throttle:5,1')->name('apply.store');
        Route::post('/loans/{loan}/restructuring', [RestructuringController::class, 'accept'])->name('restructuring.accept');
        Route::post('/loans/{loan}/pay', [PaymentController::class, 'store'])->middleware('throttle:6,1')->name('payment.store');
    });