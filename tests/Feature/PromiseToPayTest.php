<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Models\AuditTrail;
use App\Models\Borrower;
use App\Models\CollectionCase;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\PaymentWaterfallLog;
use App\Models\PromiseToPay;
use App\Services\Servicing\PromiseToPayService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00', 'Africa/Nairobi'));
});

function ptpCase(string $status = 'open'): CollectionCase
{
    $borrower = Borrower::create([
        'full_name'      => 'Promise Person',
        'id_number'      => '60000001',
        'phone_number'   => '254744000001',
        'credit_limit'   => '15000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::create([
        'name'                        => 'Ptp Test Product',
        'product_code'                => 'PTP-30',
        'min_principal'               => '1000.00',
        'max_principal'               => '50000.00',
        'default_tenure_days'         => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active'                   => true,
    ]);

    $application = LoanApplication::create([
        'application_number'    => 'APP-PTP-01',
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => $product->id,
        'requested_principal'   => '10000.00',
        'requested_tenure_days' => 30,
        'status'                => 'approved',
    ]);

    $loan = Loan::create([
        'loan_number'          => 'LN-PTP-01',
        'loan_application_id'  => $application->id,
        'borrower_id'          => $borrower->id,
        'loan_product_id'      => $product->id,
        'principal_disbursed'  => '10000.00',
        'annual_interest_rate' => '18.00',
        'tenure_days'          => 30,
        'status'               => LoanStatus::DISBURSED,
        'disbursed_at'         => CarbonImmutable::now()->subDays(40),
        'due_date'             => CarbonImmutable::now()->subDays(10),
        'is_in_duplum_locked'  => false,
    ]);

    return CollectionCase::create([
        'loan_id'     => $loan->id,
        'borrower_id' => $borrower->id,
        'dpd_bucket'  => 'DPD_1_30',
        'case_status' => $status,
    ]);
}

function ptpPay(CollectionCase $case, string $amount): void
{
    PaymentWaterfallLog::create([
        'loan_id'               => $case->loan_id,
        'payment_reference'     => 'PTP-PAY-' . $amount,
        'total_amount_received' => $amount,
    ]);
}

it('records a promise and writes an audit row', function (): void {
    $case = ptpCase();

    $promise = app(PromiseToPayService::class)->record($case, '2500', '2026-10-29');

    expect($promise->status)->toBe('pending')
        ->and((string) $promise->promised_amount)->toBe('2500.00')
        ->and($promise->promised_date->toDateString())->toBe('2026-10-29')
        ->and(AuditTrail::where('action', 'PROMISE_TO_PAY_RECORDED')->count())->toBe(1);
});

it('refuses a date in the past', function (): void {
    app(PromiseToPayService::class)->record(ptpCase(), '2500', '2026-10-20');
})->throws(RuntimeException::class, 'cannot be in the past');

it('refuses a zero amount', function (): void {
    app(PromiseToPayService::class)->record(ptpCase(), '0.00', '2026-10-29');
})->throws(RuntimeException::class, 'more than zero');

it('refuses a second live promise on the same case', function (): void {
    $case = ptpCase();
    $service = app(PromiseToPayService::class);

    $service->record($case, '2500', '2026-10-29');
    $service->record($case, '1000', '2026-10-30');
})->throws(RuntimeException::class, 'already has a promise');

it('refuses a promise on a closed case', function (): void {
    app(PromiseToPayService::class)->record(ptpCase('closed'), '2500', '2026-10-29');
})->throws(RuntimeException::class, 'closed');

it('marks a promise honored when repayments since it was made cover it', function (): void {
    $case = ptpCase();
    $promise = app(PromiseToPayService::class)->record($case, '2500', '2026-10-29');

    $this->travelTo(CarbonImmutable::parse('2026-10-27 12:00:00', 'Africa/Nairobi'));
    ptpPay($case, '1000.00');
    ptpPay($case, '1500.00');

    $stats = app(PromiseToPayService::class)->reviewAll();

    expect($promise->fresh()->status)->toBe('honored')
        ->and($promise->fresh()->honored_at)->not->toBeNull()
        ->and($stats['honored'])->toBe(1)
        ->and(AuditTrail::where('action', 'PROMISE_TO_PAY_HONORED')->count())->toBe(1);
});

it('marks a promise broken once the date has passed with too little paid', function (): void {
    $case = ptpCase();
    $promise = app(PromiseToPayService::class)->record($case, '2500', '2026-10-29');

    $this->travelTo(CarbonImmutable::parse('2026-10-27 12:00:00', 'Africa/Nairobi'));
    ptpPay($case, '500.00');

    $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00', 'Africa/Nairobi'));
    $stats = app(PromiseToPayService::class)->reviewAll();

    expect($promise->fresh()->status)->toBe('broken')
        ->and($stats['broken'])->toBe(1)
        ->and(AuditTrail::where('action', 'PROMISE_TO_PAY_BROKEN')->count())->toBe(1);
});

it('leaves a promise pending before its date when only part is paid', function (): void {
    $case = ptpCase();
    $promise = app(PromiseToPayService::class)->record($case, '2500', '2026-10-29');

    $this->travelTo(CarbonImmutable::parse('2026-10-27 12:00:00', 'Africa/Nairobi'));
    ptpPay($case, '500.00');

    $stats = app(PromiseToPayService::class)->reviewAll();

    expect($promise->fresh()->status)->toBe('pending')
        ->and($stats['honored'])->toBe(0)
        ->and($stats['broken'])->toBe(0);
});

it('runs from artisan', function (): void {
    $case = ptpCase();
    app(PromiseToPayService::class)->record($case, '2500', '2026-10-29');

    $this->artisan('loans:review-promises')
        ->assertSuccessful()
        ->expectsOutputToContain('Promise review complete');

    expect(PromiseToPay::count())->toBe(1);
});