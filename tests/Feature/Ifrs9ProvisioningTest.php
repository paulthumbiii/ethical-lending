<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Models\Borrower;
use App\Models\EclProvision;
use App\Models\Ifrs9LoanStage;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanLedgerEntry;
use App\Models\LoanProduct;
use App\Models\OutboxEvent;
use App\Services\Analytics\Ifrs9ProvisioningService;
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
        'application_number'   => 'APP-IFRS-01',
        'borrower_id'          => $this->borrower->id,
        'loan_product_id'      => $this->product->id,
        'requested_principal'  => '20000.00',
        'requested_tenure_days'=> 30,
        'status'               => 'approved',
    ]);

    // Active Performing Loan (Due in 15 days -> Stage 1)
    $this->loanStage1 = Loan::create([
        'loan_number'         => 'LN-IFRS-STG1',
        'loan_application_id' => $this->application->id,
        'borrower_id'         => $this->borrower->id,
        'loan_product_id'     => $this->product->id,
        'principal_disbursed' => '20000.00',
        'annual_interest_rate'=> '18.00',
        'tenure_days'         => 30,
        'status'              => LoanStatus::DISBURSED,
        'disbursed_at'        => CarbonImmutable::now()->subDays(15),
        'due_date'            => CarbonImmutable::now()->addDays(15),
        'is_in_duplum_locked' => false,
    ]);

    LoanLedgerEntry::create([
        'loan_id'                   => $this->loanStage1->id,
        'entry_type'                => 'disbursement',
        'debit'                     => '20000.00',
        'credit'                    => '0.00',
        'running_principal_balance' => '20000.00',
        'running_interest_balance'  => '0.00',
        'running_fees_balance'      => '0.00',
        'total_running_balance'     => '20000.00',
        'reference'                 => 'DISB-STG1',
    ]);

    $this->service = app(Ifrs9ProvisioningService::class);
});

it('accurately classifies loans into IFRS 9 Stage 1, Stage 2, and Stage 3', function (): void {
    // 1. Stage 1: Active performing loan
    expect($this->service->classifyStage($this->loanStage1))->toBe(1);

    // 2. Stage 2: Restructured facility (SICR)
    $this->loanStage1->update(['status' => LoanStatus::RESTRUCTURED]);
    expect($this->service->classifyStage($this->loanStage1))->toBe(2);

    // 3. Stage 2: 45 Days Past Due (31-89 DPD)
    $this->loanStage1->update([
        'status'   => LoanStatus::DISBURSED,
        'due_date' => CarbonImmutable::now()->subDays(45),
    ]);
    expect($this->service->classifyStage($this->loanStage1))->toBe(2);

    // 4. Stage 3: 95 Days Past Due (Defaulted / 90+ DPD)
    $this->loanStage1->update([
        'due_date' => CarbonImmutable::now()->subDays(95),
    ]);
    expect($this->service->classifyStage($this->loanStage1))->toBe(3);
});

it('computes exact mathematical ECL provision using EAD * PD * LGD', function (): void {
    // Stage 1 Loan: EAD = 20,000.00, Risk Band B -> PD = 0.0500 (5%), LGD = 0.6000 (60%)
    // Expected ECL = 20,000 * 0.05 * 0.60 = 600.00 KES
    $ecl = $this->service->calculateLoanEcl($this->loanStage1);

    expect($ecl['stage'])->toBe(1)
        ->and($ecl['ead'])->toBe('20000.00')
        ->and($ecl['pd'])->toBe('0.0500')
        ->and($ecl['lgd'])->toBe('0.6000')
        ->and($ecl['calculated_ecl'])->toBe('600.00')
        ->and($ecl['sicr_flag'])->toBeFalse();
});

it('executes portfolio ECL calculation and persists staging records and outbox events', function (): void {
    $now = CarbonImmutable::now();
    $result = $this->service->computePortfolioEcl($now);

    expect($result['total_loans_evaluated'])->toBe(1)
        ->and($result['stage_1_provisions'])->toBe('600.00')
        ->and($result['total_ecl_reserve'])->toBe('600.00');

    // Assert staging record persisted
    $stageRecord = Ifrs9LoanStage::where('loan_id', $this->loanStage1->id)->first();
    expect($stageRecord)->not->toBeNull()
        ->and($stageRecord->current_stage)->toBe(1);

    // Assert provision persisted
    $provision = EclProvision::where('loan_id', $this->loanStage1->id)->first();
    expect($provision)->not->toBeNull()
        ->and($provision->calculated_ecl)->toBe('600.00');

    // Assert outbox event committed
    $outbox = OutboxEvent::where('event_name', 'portfolio.ecl_calculated')->first();
    expect($outbox)->not->toBeNull()
        ->and($outbox->payload['total_ecl_reserve'])->toBe('600.00');
});

it('artisan command loans:calculate-ecl executes successfully', function (): void {
    $this->artisan('loans:calculate-ecl')
        ->assertSuccessful()
        ->expectsOutputToContain('IFRS 9 Expected Credit Loss')
        ->expectsOutputToContain('Stage 1 (Performing - 12M ECL)')
        ->expectsOutputToContain('KES 600.00');
});