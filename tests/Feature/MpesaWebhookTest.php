<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Jobs\ProcessMpesaCallbackJob;
use App\Models\Borrower;
use App\Models\ChartOfAccount;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanLedgerEntry;
use App\Models\LoanProduct;
use App\Models\MpesaC2bCallback;
use App\Models\MpesaStkRequest;
use App\Services\Financial\Mpesa\MpesaWebhookService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();

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
        'application_number'   => 'APP-WEBHOOK-01',
        'borrower_id'          => $this->borrower->id,
        'loan_product_id'      => $this->product->id,
        'requested_principal'  => '10000.00',
        'requested_tenure_days'=> 30,
        'status'               => 'approved',
    ]);

    $this->loan = Loan::create([
        'loan_number'         => 'LN-PAYBILL-01',
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

    // Initial baseline ledger entry: KES 10,000 principal
    LoanLedgerEntry::create([
        'loan_id'                   => $this->loan->id,
        'entry_type'                => 'disbursement',
        'debit'                     => '10000.00',
        'credit'                    => '0.00',
        'running_principal_balance' => '10000.00',
        'running_interest_balance'  => '0.00',
        'running_fees_balance'      => '0.00',
        'total_running_balance'     => '10000.00',
        'reference'                 => 'DISB-TEST',
    ]);
});

it('ingests c2b confirmation webhook, logs callback, and dispatches background job', function (): void {
    $payload = [
        'TransactionType'   => 'Pay Utility',
        'TransID'           => 'QHD91823J1',
        'TransTime'         => '20261016140000',
        'TransAmount'       => '3000.00',
        'BusinessShortCode' => '174379',
        'BillRefNumber'     => 'LN-PAYBILL-01',
        'MSISDN'            => '254712345678',
        'FirstName'         => 'Wanjiku',
    ];

    $response = $this->postJson('/api/v1/mpesa/c2b-confirmation', $payload);

    $response->assertStatus(200)
        ->assertJson([
            'ResultCode' => 0,
            'ResultDesc' => 'Confirmation received successfully',
        ]);

    expect(MpesaC2bCallback::where('trans_id', 'QHD91823J1')->exists())->toBeTrue();

    Queue::assertPushed(ProcessMpesaCallbackJob::class, function ($job) {
        return $job->sourceType === 'C2B';
    });
});

it('gracefully handles duplicate c2b webhook without creating duplicate entries', function (): void {
    MpesaC2bCallback::create([
        'transaction_type'    => 'Pay Utility',
        'trans_id'            => 'DUPLICATE_TXN_01',
        'trans_time'          => '20261016140000',
        'trans_amount'        => '5000.00',
        'business_short_code' => '174379',
        'bill_ref_number'     => 'LN-PAYBILL-01',
        'msisdn'              => '254712345678',
        'is_reconciled'       => false,
    ]);

    $payload = [
        'TransID'       => 'DUPLICATE_TXN_01', // Identical receipt ID!
        'TransAmount'   => '5000.00',
        'BillRefNumber' => 'LN-PAYBILL-01',
        'MSISDN'        => '254712345678',
    ];

    $response = $this->postJson('/api/v1/mpesa/c2b-confirmation', $payload);

    // Must return 200 OK so Safaricom stops retrying
    $response->assertStatus(200)
        ->assertJson(['ResultCode' => 0, 'ResultDesc' => 'Duplicate acknowledged']);

    // Assert only 1 record exists in database
    expect(MpesaC2bCallback::where('trans_id', 'DUPLICATE_TXN_01')->count())->toBe(1);
});

it('ingests stk push callback successfully and marks request as successful', function (): void {
    $stk = MpesaStkRequest::create([
        'loan_id'              => $this->loan->id,
        'merchant_request_id'  => 'MERCH-123',
        'checkout_request_id'  => 'ws_CO_16102026_TEST1',
        'phone_number'         => '254712345678',
        'amount'               => '4000.00',
        'status'               => 'pending',
    ]);

    $payload = [
        'Body' => [
            'stkCallback' => [
                'MerchantRequestID' => 'MERCH-123',
                'CheckoutRequestID' => 'ws_CO_16102026_TEST1',
                'ResultCode'        => 0,
                'ResultDesc'        => 'The service request is processed successfully.',
                'CallbackMetadata'  => [
                    'Item' => [
                        ['Name' => 'Amount', 'Value' => 4000.00],
                        ['Name' => 'MpesaReceiptNumber', 'Value' => 'QHD445566A'],
                        ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->postJson('/api/v1/mpesa/stk-callback', $payload);

    $response->assertStatus(200);

    $stk->refresh();
    expect($stk->status)->toBe('successful')
        ->and($stk->mpesa_receipt_number)->toBe('QHD445566A');

    Queue::assertPushed(ProcessMpesaCallbackJob::class, function ($job) use ($stk) {
        return $job->sourceType === 'STK' && $job->recordId === $stk->id;
    });
});

it('executes background repayment job through payment waterfall and gl', function (): void {
    $callback = MpesaC2bCallback::create([
        'transaction_type'    => 'Pay Utility',
        'trans_id'            => 'PAYBILL-WATERFALL-01',
        'trans_time'          => '20261016140000',
        'trans_amount'        => '2000.00',
        'business_short_code' => '174379',
        'bill_ref_number'     => 'LN-PAYBILL-01',
        'msisdn'              => '254712345678',
        'is_reconciled'       => false,
    ]);

    // Instantiate and execute service synchronously for test assertion
    $service = app(MpesaWebhookService::class);
    $service->processRepayment('C2B', $callback->id);

    $callback->refresh();
    expect($callback->is_reconciled)->toBeTrue();

    // Assert Loan Ledger updated via Waterfall: Principal reduced from 10,000 to 8,000
    $latestLedger = LoanLedgerEntry::where('loan_id', $this->loan->id)->latest('id')->first();
    expect(number_format((float) $latestLedger->running_principal_balance, 2, '.', ''))->toBe('8000.00')
        ->and(number_format((float) $latestLedger->credit, 2, '.', ''))->toBe('2000.00');
});