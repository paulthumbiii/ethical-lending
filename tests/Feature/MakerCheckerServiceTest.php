<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\MakerCheckerRequest;
use App\Models\User;
use App\Services\Governance\MakerCheckerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('creates pending request and authorizes successfully when maker and checker are different users', function () {
    /** @var User $maker */
    $maker = User::create([
        'name' => 'John Maker',
        'email' => 'maker@lender.co.ke',
        'password' => bcrypt('password'),
        'phone_number' => '254711000111',
        'is_active' => true,
    ]);

    /** @var User $checker */
    $checker = User::create([
        'name' => 'Sarah Checker',
        'email' => 'checker@lender.co.ke',
        'password' => bcrypt('password'),
        'phone_number' => '254711000222',
        'is_active' => true,
    ]);

    /** @var Borrower $borrower */
    $borrower = Borrower::create([
        'full_name' => 'David Ochieng',
        'id_number' => '21908374',
        'phone_number' => '254722334455',
        'credit_limit' => '50000.00',
        'risk_band' => \App\Enums\RiskBand::A,
        'is_blacklisted' => false,
    ]);

    /** @var LoanProduct $product */
    $product = LoanProduct::create([
        'name' => 'Emergency Advance',
        'product_code' => 'EMG_ADV',
        'min_principal' => '1000.00',
        'max_principal' => '20000.00',
        'default_tenure_days' => 14,
        'interest_calculation_method' => 'FLAT_RATE',
        'is_active' => true,
    ]);

    /** @var LoanApplication $application */
    $application = LoanApplication::create([
        'application_number' => 'APP_MK_001',
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'requested_principal' => '10000.00',
        'requested_tenure_days' => 14,
        'status' => 'approved_pending_checker',
    ]);

    /** @var MakerCheckerService $service */
    $service = $this->app->make(MakerCheckerService::class);

    $pending = $service->createPendingRequest(
        $maker->id,
        'APPROVE_LOAN_APPLICATION',
        LoanApplication::class,
        $application->id,
        ['requested_principal' => '10000.00']
    );

    expect($pending->status)->toBe('pending');

    $result = $service->authorizeRequest($pending->id, $checker->id, 'Verified income source and CRB records.');

    expect($result['status'])->toBe('approved');

    $this->assertDatabaseHas('maker_checker_requests', [
        'id' => $pending->id,
        'status' => 'approved',
        'checker_user_id' => $checker->id,
    ]);

    $this->assertDatabaseHas('loan_applications', [
        'id' => $application->id,
        'status' => 'approved',
    ]);

    $this->assertDatabaseHas('audit_trails', [
        'user_id' => $checker->id,
        'action' => 'MAKER_CHECKER_AUTHORIZED',
    ]);
});

test('throws RuntimeException when maker attempts to self approve request', function () {
    /** @var User $maker */
    $maker = User::create([
        'name' => 'Alex Operator',
        'email' => 'alex@lender.co.ke',
        'password' => bcrypt('password'),
        'phone_number' => '254711333444',
        'is_active' => true,
    ]);

    /** @var MakerCheckerService $service */
    $service = $this->app->make(MakerCheckerService::class);

    $pending = $service->createPendingRequest(
        $maker->id,
        'APPROVE_LOAN_APPLICATION',
        LoanApplication::class,
        101,
        []
    );

    // Attempting self-approval must throw RuntimeException
    $service->authorizeRequest($pending->id, $maker->id, 'Self approval attempt');
})->throws(RuntimeException::class);