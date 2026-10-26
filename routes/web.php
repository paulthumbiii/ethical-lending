<?php

declare(strict_types=1);

use App\Enums\StaffPermission;
use App\Enums\UserRole;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Borrower\ApplicationController;
use App\Http\Controllers\Borrower\PaymentController;
use App\Http\Controllers\Borrower\PortalController;
use App\Http\Controllers\Borrower\RestructuringController;
use App\Http\Controllers\Borrower\StatementController;
use App\Http\Controllers\ContractVerificationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Lender\ApplicationQueueController;
use App\Http\Controllers\Lender\BorrowerController;
use App\Http\Controllers\Lender\CollectionCaseController;
use App\Http\Controllers\Lender\CollectionsWorkbenchController;
use App\Http\Controllers\Lender\LoanController;
use App\Http\Controllers\Lender\LoanDisbursementController;
use App\Http\Controllers\Lender\MakerCheckerController;
use App\Http\Controllers\Lender\StatementReviewController;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
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

        // Borrower directory and 360-degree page: read-only, gated by permission (Phase 12.1).
        Route::middleware(PermissionMiddleware::class . ':' . StaffPermission::BorrowersView->value)
            ->prefix('borrowers')
            ->name('borrowers.')
            ->group(function () {
                Route::get('/', [BorrowerController::class, 'index'])->name('index');
                Route::get('/{borrower}', [BorrowerController::class, 'show'])->name('show');
            });

        // Loan directory and loan page: read-only; tabs are gated again inside the controller (Phase 12.2).
        Route::middleware(PermissionMiddleware::class . ':' . StaffPermission::LoansView->value)
            ->prefix('loans')
            ->name('loans.')
            ->group(function () {
                Route::get('/', [LoanController::class, 'index'])->name('index');
                Route::get('/{loan}', [LoanController::class, 'show'])->name('show');
            });

        // Applications queue: credit officers and admins act, finance officers view only.
        Route::middleware(RoleMiddleware::class . ':' . UserRole::applicationViewersPipe())
            ->prefix('applications')
            ->name('applications.')
            ->group(function () {
                Route::get('/', [ApplicationQueueController::class, 'index'])->name('index');
                Route::get('/{loanApplication}', [ApplicationQueueController::class, 'show'])->name('show');

                Route::middleware(RoleMiddleware::class . ':' . UserRole::underwritersPipe())
                    ->group(function () {
                        Route::post('/{loanApplication}/check', [ApplicationQueueController::class, 'runCheck'])->name('check');
                        Route::post('/{loanApplication}/final-approval', [ApplicationQueueController::class, 'requestApproval'])->name('final-approval');
                        // Statement evidence: documents are only for people who decide on them.
                        Route::get('/statements/{mpesaStatementUpload}/file', [StatementReviewController::class, 'file'])->name('statements.file');
                        Route::post('/statements/{mpesaStatementUpload}/password', [StatementReviewController::class, 'password'])->name('statements.password');
                        Route::post('/statements/{mpesaStatementUpload}/accept', [StatementReviewController::class, 'accept'])->name('statements.accept');
                        Route::post('/statements/{mpesaStatementUpload}/reject', [StatementReviewController::class, 'reject'])->name('statements.reject');
                    });

                // Money out: only the people who can approve (finance officer, lender admin).
                Route::middleware(RoleMiddleware::class . ':' . UserRole::approversPipe())
                    ->group(function () {
                        Route::post('/{loanApplication}/disburse', [LoanDisbursementController::class, 'store'])->name('disburse');
                        Route::post('/{loanApplication}/simulate-payout', [LoanDisbursementController::class, 'simulate'])->name('simulate-payout');
                    });
            });

        // Dual-control review desk: approvers only (lender_admin, finance_officer).
        Route::middleware(RoleMiddleware::class . ':' . UserRole::approversPipe())
            ->prefix('maker-checker')
            ->name('maker-checker.')
            ->group(function () {
                Route::get('/', [MakerCheckerController::class, 'index'])->name('index');
                Route::post('/{makerCheckerRequest}/approve', [MakerCheckerController::class, 'approve'])->name('approve');
                Route::post('/{makerCheckerRequest}/reject', [MakerCheckerController::class, 'reject'])->name('reject');
            });

        // Collections workbench list is read-only (GET only). The case page is read-only for
        // viewers; only promise recorders can post a promise to pay (Phase 14.4b).
        Route::middleware(RoleMiddleware::class . ':' . UserRole::collectionsViewersPipe())
            ->prefix('collections')
            ->name('collections.')
            ->group(function () {
                Route::get('/', [CollectionsWorkbenchController::class, 'index'])->name('index');
                Route::get('/{collectionCase}', [CollectionCaseController::class, 'show'])->name('show');

                Route::middleware(RoleMiddleware::class . ':' . UserRole::promiseRecordersPipe())
                    ->group(function () {
                        Route::post('/{collectionCase}/promises', [CollectionCaseController::class, 'storePromise'])
                            ->middleware('throttle:20,1')
                            ->name('promises.store');
                    });
            });
    });

Route::middleware(['auth', RoleMiddleware::class . ':' . UserRole::Borrower->value])
    ->prefix('borrower')
    ->name('borrower.')
    ->group(function () {
        Route::get('/dashboard', [PortalController::class, 'show'])->name('dashboard');
        Route::get('/apply', [ApplicationController::class, 'create'])->name('apply.create');
        Route::post('/apply', [ApplicationController::class, 'store'])->middleware('throttle:5,1')->name('apply.store');
        Route::post('/apply/{loanApplication}/statement', [StatementController::class, 'store'])->middleware('throttle:5,1')->name('statement.store');
        Route::post('/loans/{loan}/restructuring', [RestructuringController::class, 'accept'])->name('restructuring.accept');
        Route::post('/loans/{loan}/pay', [PaymentController::class, 'store'])->middleware('throttle:6,1')->name('payment.store');
    });

Route::middleware([
    'auth',
    \Spatie\Permission\Middleware\RoleMiddleware::class . ':' . \App\Enums\UserRole::staffPipe(),
    \Spatie\Permission\Middleware\PermissionMiddleware::class . ':finance.view',
])
    ->prefix('lender/finance')
    ->name('lender.finance.')
    ->group(function (): void {
        Route::get('/', [\App\Http\Controllers\Lender\FinanceController::class, 'index'])->name('index');
    });

Route::middleware([
    'auth',
    \Spatie\Permission\Middleware\RoleMiddleware::class . ':' . \App\Enums\UserRole::staffPipe(),
    \Spatie\Permission\Middleware\PermissionMiddleware::class . ':audit.view',
])
    ->prefix('lender/audit')
    ->name('lender.audit.')
    ->group(function (): void {
        Route::get('/', [\App\Http\Controllers\Lender\AuditLogController::class, 'index'])->name('index');
    });

Route::middleware([
    'auth',
    \Spatie\Permission\Middleware\RoleMiddleware::class . ':' . \App\Enums\UserRole::Borrower->value,
])
    ->prefix('borrower')
    ->name('borrower.')
    ->group(function (): void {
        Route::get('/account', [\App\Http\Controllers\Borrower\AccountController::class, 'show'])->name('account');
    });

Route::middleware([
    'auth',
    \Spatie\Permission\Middleware\RoleMiddleware::class . ':' . \App\Enums\UserRole::staffPipe(),
    \Spatie\Permission\Middleware\PermissionMiddleware::class . ':' . \App\Enums\StaffPermission::StatementsView->value,
])
    ->prefix('lender/borrowers/{borrower}/documents')
    ->name('lender.borrowers.documents.')
    ->group(function (): void {
        Route::get('/{document}/file', [\App\Http\Controllers\Lender\KycDocumentController::class, 'file'])->name('file');
        Route::post('/{document}/verify', [\App\Http\Controllers\Lender\KycDocumentController::class, 'verify'])->name('verify');
        Route::post('/{document}/reject', [\App\Http\Controllers\Lender\KycDocumentController::class, 'reject'])->name('reject');
    });

Route::middleware([
    'auth',
    \Spatie\Permission\Middleware\RoleMiddleware::class . ':' . \App\Enums\UserRole::Borrower->value,
])
    ->prefix('borrower')
    ->name('borrower.')
    ->group(function (): void {
        Route::post('/account/documents', [\App\Http\Controllers\Borrower\KycDocumentController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('documents.store');
    });

Route::middleware([
    'auth',
    \Spatie\Permission\Middleware\RoleMiddleware::class . ':' . \App\Enums\UserRole::Borrower->value,
])
    ->prefix('borrower')
    ->name('borrower.')
    ->group(function (): void {
        Route::get('/loans/{loan}/contract', [\App\Http\Controllers\Borrower\ContractController::class, 'show'])
            ->middleware('throttle:20,1')
            ->name('contract');
    });

Route::middleware([
    'auth',
    \Spatie\Permission\Middleware\RoleMiddleware::class . ':' . \App\Enums\UserRole::Borrower->value,
])
    ->prefix('borrower')
    ->name('borrower.')
    ->group(function (): void {
        Route::get('/notifications', [\App\Http\Controllers\Borrower\NotificationController::class, 'index'])
            ->name('notifications');
        Route::post('/notifications/read', [\App\Http\Controllers\Borrower\NotificationController::class, 'readAll'])
            ->middleware('throttle:20,1')
            ->name('notifications.read');
    });