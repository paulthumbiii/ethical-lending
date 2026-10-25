<?php

declare(strict_types=1);

use App\Enums\RiskBand;
use App\Models\Borrower;
use App\Models\FeeMatrix;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Services\Customer\ApplicationTimelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function apaSteps(string $limit, string $status = 'approved'): array
{
    $borrower = Borrower::create([
        'full_name' => 'Apa Tester',
        'id_number' => '34000001',
        'phone_number' => '254755000501',
        'credit_limit' => $limit,
        'risk_band' => RiskBand::C,
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::create([
        'name' => 'Apa Product',
        'product_code' => 'APA-30',
        'min_principal' => '1000.00',
        'max_principal' => '20000.00',
        'default_tenure_days' => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active' => true,
    ]);

    FeeMatrix::create([
        'loan_product_id' => $product->id,
        'fee_name' => 'Processing fee',
        'calculation_type' => 'percentage',
        'fee_amount' => '0.00',
        'fee_percentage' => '5.00',
        'is_excise_duty_applicable' => true,
    ]);

    $application = LoanApplication::create([
        'application_number' => 'APP-APA-' . $limit,
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'requested_principal' => '1500.00',
        'requested_tenure_days' => 30,
        'status' => $status,
    ]);

    $steps = (new ApplicationTimelineService())->build($application);

    return array_column($steps, 'detail', 'key');
}

it('tells the borrower when the approved amount is lower than requested', function () {
    $details = apaSteps('1125.00');

    expect($details['credit_check'])->toContain('Approved amount: KES 1,125')
        ->and($details['credit_check'])->toContain('you asked for KES 1,500');
});

it('shows what the borrower receives and the fee added', function () {
    $details = apaSteps('1125.00');

    expect($details['approved'])->toContain('You will receive KES 1,125')
        ->and($details['approved'])->toContain('Processing fee KES 56.25')
        ->and($details['approved'])->toContain('KES 1,181.25');
});

it('does not mention a shortfall when the full request is approved', function () {
    $details = apaSteps('5000.00');

    expect($details['credit_check'])->toContain('Approved amount: KES 1,500')
        ->and($details['credit_check'])->not->toContain('you asked for');
});