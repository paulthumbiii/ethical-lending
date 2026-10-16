<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanLegalContract;
use App\Models\LoanProduct;
use App\Models\OutboxEvent;
use App\Services\Servicing\ContractGeneratorService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->borrower = Borrower::create([
        'full_name'      => 'Wanjiku Kamau',
        'id_number'      => '28471928',
        'phone_number'   => '254712345678',
        'credit_limit'   => '50000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);

    $this->product = LoanProduct::create([
        'name'                        => 'Biashara Micro Capital',
        'product_code'                => 'BMC-30',
        'min_principal'               => '1000.00',
        'max_principal'               => '50000.00',
        'default_tenure_days'         => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active'                   => true,
    ]);

    $this->application = LoanApplication::create([
        'application_number'   => 'APP-DOC-01',
        'borrower_id'          => $this->borrower->id,
        'loan_product_id'      => $this->product->id,
        'requested_principal'  => '20000.00',
        'requested_tenure_days'=> 30,
        'status'               => 'approved',
    ]);

    $this->loan = Loan::create([
        'loan_number'         => 'LN-DOC-01',
        'loan_application_id' => $this->application->id,
        'borrower_id'         => $this->borrower->id,
        'loan_product_id'     => $this->product->id,
        'principal_disbursed' => '20000.00',
        'annual_interest_rate'=> '18.00',
        'tenure_days'         => 30,
        'status'              => LoanStatus::DISBURSED,
        'disbursed_at'        => CarbonImmutable::now(),
        'due_date'            => CarbonImmutable::now()->addDays(30),
        'is_in_duplum_locked' => false,
    ]);

    $this->service = app(ContractGeneratorService::class);
});

it('generates a tamper-proof contract with cryptographic sha256 hash and qr code', function (): void {
    $contract = $this->service->generateContract($this->loan);

    expect($contract)->toBeInstanceOf(LoanLegalContract::class)
        ->and($contract->contract_sha256_hash)->toHaveLength(64)
        ->and($contract->qr_verification_code)->toStartWith('VER-')
        ->and(file_exists(storage_path('app/' . $contract->contract_pdf_path)))->toBeTrue();

    // Verify Outbox Event created
    $outbox = OutboxEvent::where('event_name', 'contract.generated')->first();
    expect($outbox)->not->toBeNull()
        ->and($outbox->payload['loan_number'])->toBe('LN-DOC-01');
});

it('public verification endpoint validates authentic contract qr code', function (): void {
    $contract = $this->service->generateContract($this->loan);

    $response = $this->getJson('/verify/contract/' . $contract->qr_verification_code);

    $response->assertStatus(200)
        ->assertJson([
            'is_valid'       => true,
            'loan_number'    => 'LN-DOC-01',
            'borrower_name'  => 'Wanjiku Kamau',
            'principal'      => '20,000.00',
            'cbk_compliance' => 'VERIFIED_CBK_DCP_COMPLIANT',
        ]);
});

it('public verification endpoint returns 404 on forged or invalid qr code', function (): void {
    $response = $this->getJson('/verify/contract/FORGED-CODE-999');

    $response->assertStatus(404)
        ->assertJson([
            'is_valid' => false,
        ]);
});