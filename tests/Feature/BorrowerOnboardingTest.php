<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RiskBand;
use App\Exceptions\Fintech\DataProtectionConsentMissingException;
use App\Models\AuditTrail;
use App\Models\Borrower;
use App\Models\BorrowerFinancialProfile;
use App\Models\DataProtectionConsent;
use App\Services\Customer\BorrowerOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('registers borrower successfully with normalized E.164 phone number', function () {
    /** @var BorrowerOnboardingService $service */
    $service = $this->app->make(BorrowerOnboardingService::class);

    $payload = [
        'full_name' => 'Wanjiku Kamau',
        'id_number' => '32891042',
        'phone_number' => '0712345678',
        'kra_pin' => 'A019283746X',
        'email' => 'wanjiku@example.com',
        'monthly_income' => '85000.00',
        'monthly_expenses' => '35000.00',
        'mpesa_average_monthly_inflow' => '90000.00',
        'existing_debt_obligations' => '5000.00',
        'data_protection_consent' => true,
    ];

    $borrower = $service->register($payload, '192.168.1.10', 'PestTestAgent/1.0');

    expect($borrower)->toBeInstanceOf(Borrower::class)
        ->and($borrower->phone_number)->toBe('254712345678')
        ->and($borrower->id_number)->toBe('32891042')
        ->and($borrower->risk_band)->toBe(RiskBand::C);

    $this->assertDatabaseHas('borrowers', [
        'id' => $borrower->id,
        'phone_number' => '254712345678',
        'id_number' => '32891042',
    ]);

    $this->assertDatabaseHas('borrower_financial_profiles', [
        'borrower_id' => $borrower->id,
        'monthly_income' => '85000.00',
        'monthly_expenses' => '35000.00',
    ]);

    $this->assertDatabaseHas('data_protection_consents', [
        'borrower_id' => $borrower->id,
        'is_granted' => true,
        'ip_address' => '192.168.1.10',
    ]);

    $this->assertDatabaseHas('audit_trails', [
        'action' => 'BORROWER_ONBOARDED',
        'auditable_type' => Borrower::class,
        'auditable_id' => $borrower->id,
    ]);
});

test('throws DataProtectionConsentMissingException when consent is not granted', function () {
    /** @var BorrowerOnboardingService $service */
    $service = $this->app->make(BorrowerOnboardingService::class);

    $payload = [
        'full_name' => 'John Doe',
        'id_number' => '12345678',
        'phone_number' => '0700000000',
        'monthly_income' => '50000.00',
        'monthly_expenses' => '20000.00',
        'data_protection_consent' => false,
    ];

    $service->register($payload);
})->throws(DataProtectionConsentMissingException::class);