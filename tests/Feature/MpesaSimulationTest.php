<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Models\Borrower;
use App\Models\ChartOfAccount;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanLedgerEntry;
use App\Models\LoanProduct;
use App\Models\MpesaC2bCallback;
use App\Models\MpesaStkRequest;
use App\Services\Financial\Mpesa\MpesaSimulationHarness;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    // Seed General Ledger Chart of Accounts
    ChartOfAccount::create(['account_code' => '1010', 'account_name' => 'M-Pesa Settlement Account', 'account_type' => 'ASSET', 'currency' => 'KES', 'is_active' => true]);
    ChartOfAccount::create(['account_code' => '1200', 'account_name' => 'Loans Receivable', 'account_type' => 'ASSET', 'currency' => 'KES', 'is_active' => true]);
    ChartOfAccount::create(['account_code' => '2010', 'account_name' => 'Customer Overpayment Credits', 'account_type' => 'LIABILITY', 'currency' => 'KES', 'is_active' => true]);
    ChartOfAccount::create(['account_code' => '4010', 'account_name' => 'Interest Income', 'account_type' => 'REVENUE', 'currency' => 'KES', 'is_active' => true]);
    ChartOfAccount::create(['account_code' => '4020', 'account_name' => 'Fee Income', 'account_type' => 'REVENUE', 'currency' => 'KES', 'is_active' => true]);

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
        'application_number'   => 'APP-SIM-01',
        'borrower_id'          => $this->borrower->id,
        'loan_product_id'      => $this->product->id,
        'requested_principal'  => '10000.00',
        'requested_tenure_days'=> 30,
        'status'               => 'approved',
    ]);

    $this->loan = Loan::create([
        'loan_number'         => 'LN-SIM-01',
        'loan_application_id' => $this->application->id,
        'borrower_id'         => $this->borrower->id,
        'loan_product_id'     => $this->product->id,
        'principal_disbursed' => '10000.00',
        'annual_interest_rate'=> '15.00',
        'tenure_days'         => 30,
        'status'              => LoanStatus::DISBURSED,
        'disbursed_at'        => CarbonImmutable::now()->subDays(10),
        'due_date'            => CarbonImmutable::now()->addDays(20),
        'is_in_duplum_locked' => false,
    ]);

    // Initial Ledger state: KES 10,000 principal
    LoanLedgerEntry::create([
        'loan_id'                   => $this->loan->id,
        'entry_type'                => 'disbursement',
        'debit'                     => '10000.00',
        'credit'                    => '0.00',
        'running_principal_balance' => '10000.00',
        'running_interest_balance'  => '0.00',
        'running_fees_balance'      => '0.00',
        'total_running_balance'     => '10000.00',
        'reference'                 => 'DISB-SIM-01',
    ]);

    $this->harness = app(MpesaSimulationHarness::class);
});

it('simulates inbound c2b paybill repayment and updates loan balance through waterfall', function (): void {
    $result = $this->harness->simulateC2bPayment($this->loan, '3000.00');

    expect($result['status'])->toBe('processed')
        ->and(MpesaC2bCallback::where('trans_id', $result['trans_id'])->exists())->toBeTrue();

    // Verify waterfall reduction in ledger (10,000 -> 7,000)
    $latest = LoanLedgerEntry::where('loan_id', $this->loan->id)->latest('id')->first();
    expect(number_format((float) $latest->running_principal_balance, 2, '.', ''))->toBe('7000.00');
});

it('simulates successful stk push callback with realistic daraja metadata', function (): void {
    $result = $this->harness->simulateStkSuccess($this->loan, '5000.00');

    expect($result['status'])->toBe('successful')
        ->and(MpesaStkRequest::where('checkout_request_id', $result['checkout_request_id'])->first()->status)->toBe('successful');

    // Verify waterfall reduction in ledger (10,000 -> 5,000)
    $latest = LoanLedgerEntry::where('loan_id', $this->loan->id)->latest('id')->first();
    expect(number_format((float) $latest->running_principal_balance, 2, '.', ''))->toBe('5000.00');
});

it('simulates user-cancelled stk push callback without touching loan balances', function (): void {
    $stk = $this->harness->simulateStkCancelled($this->loan, '2000.00');

    expect($stk->status)->toBe('failed')
        ->and($stk->result_code)->toBe(1032);

    // Assert ledger remains completely unchanged at 10,000
    $latest = LoanLedgerEntry::where('loan_id', $this->loan->id)->latest('id')->first();
    expect(number_format((float) $latest->running_principal_balance, 2, '.', ''))->toBe('10000.00');
});

it('artisan command mpesa:simulate executes successfully from cli', function (): void {
    $this->artisan('mpesa:simulate --type=c2b --amount=1500.00')
        ->assertSuccessful()
        ->expectsOutputToContain('M-Pesa Paybill (C2B)')
        ->expectsOutputToContain('KES 1,500.00');

    $latest = LoanLedgerEntry::where('loan_id', $this->loan->id)->latest('id')->first();
    expect(number_format((float) $latest->running_principal_balance, 2, '.', ''))->toBe('8500.00');
});