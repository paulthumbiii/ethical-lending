<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Models\Borrower;
use App\Models\InDuplumTracking;
use App\Models\Loan;
use App\Services\Customer\BorrowerPortalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function portalLoan(array $attributes = [], ?array $tracking = null): Loan
{
    $loan = (new Loan())->forceFill($attributes);
    $loan->setRelation(
        'inDuplumTracking',
        $tracking !== null ? (new InDuplumTracking())->forceFill($tracking) : null
    );

    return $loan;
}

function portalTracking(string $interest, string $penalties, bool $frozen = false): array
{
    return [
        'default_principal_snapshot'   => '10000.00',
        'statutory_cap_limit'          => '10000.00',
        'total_post_default_interest'  => $interest,
        'total_post_default_penalties' => $penalties,
        'is_frozen'                    => $frozen,
    ];
}

it('shows the meter as not triggered when the loan has not defaulted', function (): void {
    $meter = app(BorrowerPortalService::class)->inDuplumMeter(portalLoan());

    expect($meter['state'])->toBe('not_triggered')
        ->and($meter['accrued'])->toBe('0.00');
});

it('shows charges counting towards the cap', function (): void {
    $meter = app(BorrowerPortalService::class)
        ->inDuplumMeter(portalLoan([], portalTracking('3000.00', '1000.00')));

    expect($meter['state'])->toBe('accruing')
        ->and($meter['accrued'])->toBe('4000.00')
        ->and($meter['percent'])->toBe('40.00')
        ->and($meter['headroom'])->toBe('6000.00');
});

it('shows charges stopped when the cap is reached', function (): void {
    $meter = app(BorrowerPortalService::class)
        ->inDuplumMeter(portalLoan([], portalTracking('7000.00', '3000.00', true)));

    expect($meter['state'])->toBe('frozen')
        ->and($meter['percent'])->toBe('100.00')
        ->and($meter['headroom'])->toBe('0.00');
});

it('never shows more than 100 percent or negative room', function (): void {
    $meter = app(BorrowerPortalService::class)
        ->inDuplumMeter(portalLoan([], portalTracking('8000.00', '2500.00')));

    expect($meter['percent'])->toBe('100.00')
        ->and($meter['headroom'])->toBe('0.00');
});

it('offers relief only on defaulted or overdue loans', function (): void {
    $service = app(BorrowerPortalService::class);

    expect($service->canRequestRelief(portalLoan(['status' => LoanStatus::DEFAULTED])))->toBeTrue()
        ->and($service->canRequestRelief(portalLoan([
            'status'   => LoanStatus::DISBURSED,
            'due_date' => now()->subDays(3)->toDateString(),
        ])))->toBeTrue()
        ->and($service->canRequestRelief(portalLoan([
            'status'   => LoanStatus::DISBURSED,
            'due_date' => now()->addDays(10)->toDateString(),
        ])))->toBeFalse()
        ->and($service->canRequestRelief(portalLoan(['status' => LoanStatus::SETTLED])))->toBeFalse()
        ->and($service->canRequestRelief(portalLoan(['status' => LoanStatus::RESTRUCTURED])))->toBeFalse();
});

it('previews relief with penalties removed and two instalments', function (): void {
    $preview = app(BorrowerPortalService::class)->reliefPreview(new Borrower(), [
        'principal' => '20000.00',
        'interest'  => '1500.00',
        'fees'      => '2500.00',
    ]);

    expect($preview['eligible'])->toBeTrue()
        ->and($preview['owed_today'])->toBe('24000.00')
        ->and($preview['waived'])->toBe('2500.00')
        ->and($preview['owed_after'])->toBe('21500.00')
        ->and($preview['first_amount'])->toBe('10750.00')
        ->and($preview['second_amount'])->toBe('10750.00');
});

it('puts any leftover cents on the second instalment', function (): void {
    $preview = app(BorrowerPortalService::class)->reliefPreview(new Borrower(), [
        'principal' => '20000.00',
        'interest'  => '1501.01',
        'fees'      => '2500.00',
    ]);

    expect($preview['owed_after'])->toBe('21501.01')
        ->and($preview['first_amount'])->toBe('10750.50')
        ->and($preview['second_amount'])->toBe('10750.51');
});