<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Http\Requests\CollectionsWorkbenchFilterRequest;
use App\Models\AmortizationSchedule;
use App\Models\AuditTrail;
use App\Models\Borrower;
use App\Models\CollectionCase;
use App\Models\InDuplumTracking;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Services\Financial\LoanLedgerService;
use App\Services\Servicing\OverdueDetectionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00', 'Africa/Nairobi'));
});

function ovdLoan(string $n, int $dueDaysAgo, LoanStatus $status = LoanStatus::DISBURSED, bool $ledger = true): Loan
{
    $borrower = Borrower::create([
        'full_name'      => 'Overdue ' . $n,
        'id_number'      => '4000' . $n,
        'phone_number'   => '254722000' . $n,
        'credit_limit'   => '15000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::firstOrCreate(
        ['product_code' => 'OVD-30'],
        [
            'name'                        => 'Overdue Test Product',
            'min_principal'               => '1000.00',
            'max_principal'               => '50000.00',
            'default_tenure_days'         => 30,
            'interest_calculation_method' => 'reducing_balance',
            'is_active'                   => true,
        ]
    );

    $application = LoanApplication::create([
        'application_number'    => 'APP-OVD-' . $n,
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => $product->id,
        'requested_principal'   => '10000.00',
        'requested_tenure_days' => 30,
        'status'                => 'approved',
    ]);

    $loan = Loan::create([
        'loan_number'          => 'LN-OVD-' . $n,
        'loan_application_id'  => $application->id,
        'borrower_id'          => $borrower->id,
        'loan_product_id'      => $product->id,
        'principal_disbursed'  => '10000.00',
        'annual_interest_rate' => '18.00',
        'tenure_days'          => 30,
        'status'               => $status,
        'disbursed_at'         => CarbonImmutable::now()->subDays($dueDaysAgo + 30),
        'due_date'             => CarbonImmutable::now()->subDays($dueDaysAgo),
        'is_in_duplum_locked'  => false,
    ]);

    if ($ledger) {
        app(LoanLedgerService::class)->recordEntry($loan->id, 'DISBURSEMENT_PRINCIPAL', '10000.00', '0.00', 'OVD-' . $n, 'test');
    }

    return $loan;
}

it('opens a collection case at 10 days past due', function (): void {
    $loan = ovdLoan('001', 10);

    $stats = app(OverdueDetectionService::class)->detectAll();

    $case = CollectionCase::where('loan_id', $loan->id)->first();
    expect($case)->not->toBeNull()
        ->and($case->dpd_bucket)->toBe('DPD_1_30')
        ->and($case->case_status)->toBe('open')
        ->and($stats['opened'])->toBe(1)
        ->and(AuditTrail::where('action', 'COLLECTION_CASE_OPENED')->count())->toBe(1);
});

it('does not open a case for a loan that is not yet due', function (): void {
    ovdLoan('002', -5);

    app(OverdueDetectionService::class)->detectAll();

    expect(CollectionCase::count())->toBe(0);
});

it('moves an existing case to a new bucket without creating a second case', function (): void {
    $loan = ovdLoan('003', 45);
    CollectionCase::create([
        'loan_id'     => $loan->id,
        'borrower_id' => $loan->borrower_id,
        'dpd_bucket'  => 'DPD_1_30',
        'case_status' => 'open',
    ]);

    $stats = app(OverdueDetectionService::class)->detectAll();

    expect(CollectionCase::count())->toBe(1)
        ->and(CollectionCase::first()->dpd_bucket)->toBe('DPD_31_60')
        ->and($stats['updated'])->toBe(1)
        ->and(AuditTrail::where('action', 'COLLECTION_BUCKET_CHANGED')->count())->toBe(1);
});

it('is idempotent when run twice on the same day', function (): void {
    ovdLoan('004', 10);
    $service = app(OverdueDetectionService::class);

    $service->detectAll();
    $second = $service->detectAll();

    expect($second['opened'])->toBe(0)
        ->and($second['updated'])->toBe(0)
        ->and(CollectionCase::count())->toBe(1)
        ->and(AuditTrail::where('action', 'COLLECTION_CASE_OPENED')->count())->toBe(1);
});

it('moves a loan to default at 90 days past due through the in-duplum snapshot', function (): void {
    $loan = ovdLoan('005', 95);

    $stats = app(OverdueDetectionService::class)->detectAll();

    $tracking = InDuplumTracking::where('loan_id', $loan->id)->first();
    expect($loan->fresh()->status)->toBe(LoanStatus::DEFAULTED)
        ->and($tracking)->not->toBeNull()
        ->and((string) $tracking->default_principal_snapshot)->toBe('10000.00')
        ->and(CollectionCase::first()->dpd_bucket)->toBe('DPD_90_PLUS')
        ->and($stats['defaulted'])->toBe(1);
});

it('skips settled loans and loans with no balance', function (): void {
    ovdLoan('006', 20, LoanStatus::SETTLED);
    ovdLoan('007', 20, LoanStatus::DISBURSED, false);

    app(OverdueDetectionService::class)->detectAll();

    expect(CollectionCase::count())->toBe(0);
});

it('counts days from the oldest unpaid instalment, not the final due date', function (): void {
    $loan = ovdLoan('008', -23);

    AmortizationSchedule::forceCreate([
        'loan_id'               => $loan->id,
        'installment_number'    => 1,
        'due_date'              => CarbonImmutable::now()->subDays(37)->toDateString(),
        'principal_due'         => '5000.00',
        'interest_due'          => '100.00',
        'fees_due'              => '0.00',
        'total_installment_due' => '5100.00',
        'status'                => 'paid',
    ]);
    AmortizationSchedule::forceCreate([
        'loan_id'               => $loan->id,
        'installment_number'    => 2,
        'due_date'              => CarbonImmutable::now()->subDays(7)->toDateString(),
        'principal_due'         => '5000.00',
        'interest_due'          => '100.00',
        'fees_due'              => '0.00',
        'total_installment_due' => '5100.00',
        'status'                => 'pending',
    ]);

    expect(app(OverdueDetectionService::class)->daysPastDue($loan))->toBe(7);
});

it('runs from artisan and opens the case', function (): void {
    ovdLoan('009', 10);

    $this->artisan('loans:detect-overdue')
        ->assertSuccessful()
        ->expectsOutputToContain('Overdue detection complete');

    expect(CollectionCase::count())->toBe(1);
});

it('uses the same bucket codes as the workbench filter', function (): void {
    $expected = [1 => 'DPD_1_30', 30 => 'DPD_1_30', 31 => 'DPD_31_60', 60 => 'DPD_31_60', 61 => 'DPD_61_90', 90 => 'DPD_61_90', 91 => 'DPD_90_PLUS'];

    foreach ($expected as $days => $bucket) {
        expect(OverdueDetectionService::bucketFor($days))->toBe($bucket)
            ->and(array_keys(CollectionsWorkbenchFilterRequest::BUCKETS))->toContain($bucket);
    }
});