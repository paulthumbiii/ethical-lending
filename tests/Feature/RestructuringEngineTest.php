<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Exceptions\Fintech\RestructuringNotEligibleException;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanLedgerEntry;
use App\Models\LoanProduct;
use App\Models\OutboxEvent;
use App\Models\RestructuringProposal;
use App\Services\Servicing\RestructuringEngine;
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
        'application_number'   => 'APP-RST-01',
        'borrower_id'          => $this->borrower->id,
        'loan_product_id'      => $this->product->id,
        'requested_principal'  => '20000.00',
        'requested_tenure_days'=> 30,
        'status'               => 'approved',
    ]);

    $this->loan = Loan::create([
        'loan_number'         => 'LN-RST-01',
        'loan_application_id' => $this->application->id,
        'borrower_id'         => $this->borrower->id,
        'loan_product_id'     => $this->product->id,
        'principal_disbursed' => '20000.00',
        'annual_interest_rate'=> '18.00',
        'tenure_days'         => 30,
        'status'              => LoanStatus::DISBURSED,
        'disbursed_at'        => CarbonImmutable::now()->subDays(35),
        'due_date'            => CarbonImmutable::now()->subDays(5), // 5 days overdue!
        'is_in_duplum_locked' => false,
    ]);

    // 1. Initial Disbursement: KES 20,000 principal
    LoanLedgerEntry::create([
        'loan_id'                   => $this->loan->id,
        'entry_type'                => 'disbursement',
        'debit'                     => '20000.00',
        'credit'                    => '0.00',
        'running_principal_balance' => '20000.00',
        'running_interest_balance'  => '0.00',
        'running_fees_balance'      => '0.00',
        'total_running_balance'     => '20000.00',
        'reference'                 => 'INIT-DISB',
    ]);

    // 2. Partial Repayment: Borrower repaid KES 10,000 (Repayment Ratio = 10k/20k = 50%)
    LoanLedgerEntry::create([
        'loan_id'                   => $this->loan->id,
        'entry_type'                => 'repayment',
        'debit'                     => '0.00',
        'credit'                    => '10000.00',
        'running_principal_balance' => '10000.00',
        'running_interest_balance'  => '0.00',
        'running_fees_balance'      => '0.00',
        'total_running_balance'     => '10000.00',
        'reference'                 => 'PARTIAL-REPAY',
    ]);

    // 3. Late penalty and interest accrued: KES 1,500 interest, KES 1,000 penalty fees
    LoanLedgerEntry::create([
        'loan_id'                   => $this->loan->id,
        'entry_type'                => 'penalty_fee',
        'debit'                     => '2500.00',
        'credit'                    => '0.00',
        'running_principal_balance' => '10000.00',
        'running_interest_balance'  => '1500.00',
        'running_fees_balance'      => '1000.00',
        'total_running_balance'     => '12500.00',
        'reference'                 => 'LATE-ACCRUAL',
    ]);

    $this->engine = app(RestructuringEngine::class);
});

it('accurately computes borrower historical repayment ratio', function (): void {
    $ratio = $this->engine->calculateRepaymentRatio($this->borrower);

    // 10,000 repaid / 20,000 disbursed = 0.50 (50%)
    expect($ratio)->toBe('0.50');
});

it('generates a tailored restructuring proposal with 100% penalty fee forgiveness', function (): void {
    $proposal = $this->engine->generateProposal($this->loan, tenureExtensionDays: 30);

    expect($proposal)->toBeInstanceOf(RestructuringProposal::class)
        ->and($proposal->status)->toBe('offered')
        ->and($proposal->tenure_extension_days)->toBe(30)
        ->and($proposal->forgiven_penalties)->toBe('1000.00') // All 1,000 late fees forgiven!
        ->and($proposal->capitalized_principal)->toBe('10000.00');

    // Assert re-amortization schedules created (2 bi-weekly installments)
    expect($proposal->schedules)->toHaveCount(2);
});

it('accepting a proposal transitions loan to RESTRUCTURED and updates ledger', function (): void {
    $proposal = $this->engine->generateProposal($this->loan, tenureExtensionDays: 45);

    $restructuredLoan = $this->engine->acceptProposal($proposal);

    expect($restructuredLoan->status)->toBe(LoanStatus::RESTRUCTURED);

    $proposal->refresh();
    expect($proposal->status)->toBe('accepted')
        ->and($proposal->accepted_at)->not->toBeNull();

    // Verify ledger entry clearing the 1,000 penalty fees
    $latest = LoanLedgerEntry::where('loan_id', $this->loan->id)->latest('id')->first();
    expect($latest->entry_type)->toBe('restructure_waiver')
        ->and($latest->credit)->toBe('1000.00')
        ->and($latest->running_fees_balance)->toBe('0.00');

    // Verify outbox event emitted
    $outbox = OutboxEvent::where('aggregate_id', $this->loan->id)
        ->where('event_name', 'loan.restructured')
        ->first();

    expect($outbox)->not->toBeNull()
        ->and($outbox->payload['proposal_code'])->toBe($proposal->proposal_code);
});

it('rejects restructuring and throws RestructuringNotEligibleException for zero-ratio borrowers', function (): void {
    // 1. Create a distinct borrower with 0 repayment history
    $zeroBorrower = Borrower::create([
        'full_name'      => 'Defaulter John',
        'id_number'      => '11223344',
        'phone_number'   => '254799887766',
        'credit_limit'   => '10000.00',
        'risk_band'      => RiskBand::D,
        'is_blacklisted' => false,
    ]);

    // 2. Create distinct application to respect unique constraint
    $app2 = LoanApplication::create([
        'application_number'   => 'APP-ZERO-RATIO',
        'borrower_id'          => $zeroBorrower->id,
        'loan_product_id'      => $this->product->id,
        'requested_principal'  => '20000.00',
        'requested_tenure_days'=> 30,
        'status'               => 'approved',
    ]);

    // 3. Create loan with zero repayments
    $unpaidLoan = Loan::create([
        'loan_number'         => 'LN-ZERO-RATIO',
        'loan_application_id' => $app2->id,
        'borrower_id'         => $zeroBorrower->id,
        'loan_product_id'     => $this->product->id,
        'principal_disbursed' => '20000.00',
        'annual_interest_rate'=> '18.00',
        'tenure_days'         => 30,
        'status'              => LoanStatus::DEFAULTED,
        'is_in_duplum_locked' => false,
    ]);

    // Zero repayment entries: Disbursed 20,000, Repaid 0.00 -> Ratio = 0.00
    LoanLedgerEntry::create([
        'loan_id'                   => $unpaidLoan->id,
        'entry_type'                => 'disbursement',
        'debit'                     => '20000.00',
        'credit'                    => '0.00',
        'running_principal_balance' => '20000.00',
        'running_interest_balance'  => '0.00',
        'running_fees_balance'      => '0.00',
        'total_running_balance'     => '20000.00',
        'reference'                 => 'DISB-ZERO',
    ]);

    // Ratio is 0.00 < 0.30 -> Must throw RestructuringNotEligibleException
    $this->engine->generateProposal($unpaidLoan);
})->throws(RestructuringNotEligibleException::class);