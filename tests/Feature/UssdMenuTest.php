<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Models\Borrower;
use App\Models\InDuplumTracking;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanLedgerEntry;
use App\Models\LoanProduct;
use App\Models\MpesaStkRequest;
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
        'application_number'   => 'APP-USSD-01',
        'borrower_id'          => $this->borrower->id,
        'loan_product_id'      => $this->product->id,
        'requested_principal'  => '10000.00',
        'requested_tenure_days'=> 30,
        'status'               => 'approved',
    ]);

    $this->loan = Loan::create([
        'loan_number'         => 'LN-USSD-01',
        'loan_application_id' => $this->application->id,
        'borrower_id'         => $this->borrower->id,
        'loan_product_id'     => $this->product->id,
        'principal_disbursed' => '10000.00',
        'annual_interest_rate'=> '18.00',
        'tenure_days'         => 30,
        'status'              => LoanStatus::DISBURSED,
        'disbursed_at'        => CarbonImmutable::now()->subDays(20),
        'due_date'            => CarbonImmutable::now()->addDays(10),
        'is_in_duplum_locked' => false,
    ]);

    LoanLedgerEntry::create([
        'loan_id'                   => $this->loan->id,
        'entry_type'                => 'disbursement',
        'debit'                     => '10000.00',
        'credit'                    => '0.00',
        'running_principal_balance' => '10000.00',
        'running_interest_balance'  => '1000.00',
        'running_fees_balance'      => '500.00',
        'total_running_balance'     => '11500.00',
        'reference'                 => 'DISB-USSD',
    ]);
});

it('renders ussd root menu with borrower personalized welcome', function (): void {
    $response = $this->post('/api/v1/ussd', [
        'sessionId'   => 'SESS-001',
        'serviceCode' => '*384#',
        'phoneNumber' => '+254712345678',
        'text'        => '',
    ]);

    $response->assertStatus(200)
        ->assertSee('CON Welcome to M-Kopo Safi, Wanjiku')
        ->assertSee('1. Check Loan Balance')
        ->assertSee('3. Repay via M-Pesa')
        ->assertSee('4. Request Restructuring Relief');
});

it('renders ussd balance check with exact ledger breakdown and in-duplum notice', function (): void {
    InDuplumTracking::create([
        'loan_id'                      => $this->loan->id,
        'defaulted_at'                 => CarbonImmutable::now(),
        'default_principal_snapshot'   => '10000.00',
        'total_post_default_interest'  => '0.00',
        'total_post_default_penalties' => '0.00',
        'statutory_cap_limit'          => '10000.00',
        'is_frozen'                    => false,
    ]);

    $response = $this->post('/api/v1/ussd', [
        'sessionId'   => 'SESS-002',
        'serviceCode' => '*384#',
        'phoneNumber' => '0712345678',
        'text'        => '1', // User selected Option 1
    ]);

    $response->assertStatus(200)
        ->assertSee('END M-Kopo Safi Balance:')
        ->assertSee('Principal: KES 10,000.00')
        ->assertSee('Total Due: KES 11,500.00')
        ->assertSee('Protected under Sec 44A In-Duplum.');
});

it('triggers mpesa stk push repayment prompt from ussd menu', function (): void {
    $response = $this->post('/api/v1/ussd', [
        'sessionId'   => 'SESS-003',
        'serviceCode' => '*384#',
        'phoneNumber' => '254712345678',
        'text'        => '3*2500', // Option 3 (Repay), Amount 2,500
    ]);

    $response->assertStatus(200)
        ->assertSee('END An M-Pesa prompt for KES 2,500.00 has been sent to your phone.');

    expect(MpesaStkRequest::where('loan_id', $this->loan->id)->where('amount', '2500')->exists())->toBeTrue();
});

it('offers and accepts restructuring agreement entirely over ussd', function (): void {
    // 1. Borrower has prior repayments to establish ratio
    LoanLedgerEntry::create([
        'loan_id'                   => $this->loan->id,
        'entry_type'                => 'repayment',
        'debit'                     => '0.00',
        'credit'                    => '5000.00',
        'running_principal_balance' => '5000.00',
        'running_interest_balance'  => '1000.00',
        'running_fees_balance'      => '500.00',
        'total_running_balance'     => '6500.00',
        'reference'                 => 'PRIOR-REPAY',
    ]);

    // Step A: Dial Option 4 (Request Restructuring)
    $responseOffer = $this->post('/api/v1/ussd', [
        'sessionId'   => 'SESS-004',
        'serviceCode' => '*384#',
        'phoneNumber' => '254712345678',
        'text'        => '4',
    ]);

    $responseOffer->assertStatus(200)
        ->assertSee('CON Relief Offer for Loan #LN-USSD-01:')
        ->assertSee('100% Late Fee Waiver: KES 500.00')
        ->assertSee('Tenure Extended: +30 Days')
        ->assertSee('1. Accept Relief Offer');

    // Step B: Borrower enters '1' to Accept Offer (4*1)
    $responseAccept = $this->post('/api/v1/ussd', [
        'sessionId'   => 'SESS-004',
        'serviceCode' => '*384#',
        'phoneNumber' => '254712345678',
        'text'        => '4*1',
    ]);

    $responseAccept->assertStatus(200)
        ->assertSee('END Relief Offer Accepted! Your loan has been restructured');

    $this->loan->refresh();
    expect($this->loan->status)->toBe(LoanStatus::RESTRUCTURED);
});