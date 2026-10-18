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
use App\Services\Financial\Mpesa\MpesaWebhookService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function msiLoan(): Loan
{
    ChartOfAccount::create(['account_code' => '1010', 'account_name' => 'M-Pesa Settlement Account', 'account_type' => 'ASSET', 'currency' => 'KES', 'is_active' => true]);
    ChartOfAccount::create(['account_code' => '1200', 'account_name' => 'Loans Receivable', 'account_type' => 'ASSET', 'currency' => 'KES', 'is_active' => true]);
    ChartOfAccount::create(['account_code' => '2010', 'account_name' => 'Customer Overpayment Credits', 'account_type' => 'LIABILITY', 'currency' => 'KES', 'is_active' => true]);
    ChartOfAccount::create(['account_code' => '4010', 'account_name' => 'Interest Income', 'account_type' => 'REVENUE', 'currency' => 'KES', 'is_active' => true]);
    ChartOfAccount::create(['account_code' => '4020', 'account_name' => 'Fee Income', 'account_type' => 'REVENUE', 'currency' => 'KES', 'is_active' => true]);

    $borrower = Borrower::create([
        'full_name'      => 'Idempotency Tester',
        'id_number'      => '32000001',
        'phone_number'   => '254744000001',
        'credit_limit'   => '50000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::create([
        'name'                        => 'Idempotency Product',
        'product_code'                => 'MSI-30',
        'min_principal'               => '1000.00',
        'max_principal'               => '50000.00',
        'default_tenure_days'         => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active'                   => true,
    ]);

    $application = LoanApplication::create([
        'application_number'    => 'APP-MSI-01',
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => $product->id,
        'requested_principal'   => '10000.00',
        'requested_tenure_days' => 30,
        'status'                => 'approved',
    ]);

    $loan = Loan::create([
        'loan_number'         => 'LN-MSI-01',
        'loan_application_id' => $application->id,
        'borrower_id'         => $borrower->id,
        'loan_product_id'     => $product->id,
        'principal_disbursed' => '10000.00',
        'annual_interest_rate'=> '15.00',
        'tenure_days'         => 30,
        'status'              => LoanStatus::DISBURSED,
        'disbursed_at'        => CarbonImmutable::now()->subDays(10),
        'due_date'            => CarbonImmutable::now()->addDays(20),
        'is_in_duplum_locked' => false,
    ]);

    LoanLedgerEntry::create([
        'loan_id'                   => $loan->id,
        'entry_type'                => 'disbursement',
        'debit'                     => '10000.00',
        'credit'                    => '0.00',
        'running_principal_balance' => '10000.00',
        'running_interest_balance'  => '0.00',
        'running_fees_balance'      => '0.00',
        'total_running_balance'     => '10000.00',
        'reference'                 => 'DISB-MSI',
    ]);

    return $loan;
}

it('credits an STK payment only once when the same job runs twice', function (): void {
    $loan = msiLoan();

    $stk = MpesaStkRequest::create([
        'loan_id'              => $loan->id,
        'merchant_request_id'  => 'MERCH-MSI-1',
        'checkout_request_id'  => 'ws_CO_MSI_1',
        'phone_number'         => '254744000001',
        'amount'               => '2000.00',
        'mpesa_receipt_number' => 'QMSI111111',
        'status'               => 'successful',
    ]);

    $service = app(MpesaWebhookService::class);

    $service->processRepayment('STK', $stk->id);
    $service->processRepayment('STK', $stk->id);

    $latest = LoanLedgerEntry::where('loan_id', $loan->id)->latest('id')->first();

    expect(number_format((float) $latest->running_principal_balance, 2, '.', ''))->toBe('8000.00');
    expect(LoanLedgerEntry::where('loan_id', $loan->id)->where('entry_type', 'repayment')->count())->toBe(1);
    expect(DB::table('payment_waterfall_logs')->where('payment_reference', 'QMSI111111')->count())->toBe(1);
});

it('still credits two different STK receipts separately', function (): void {
    $loan = msiLoan();

    $first = MpesaStkRequest::create([
        'loan_id'              => $loan->id,
        'merchant_request_id'  => 'MERCH-MSI-2',
        'checkout_request_id'  => 'ws_CO_MSI_2',
        'phone_number'         => '254744000001',
        'amount'               => '2000.00',
        'mpesa_receipt_number' => 'QMSI222222',
        'status'               => 'successful',
    ]);

    $second = MpesaStkRequest::create([
        'loan_id'              => $loan->id,
        'merchant_request_id'  => 'MERCH-MSI-3',
        'checkout_request_id'  => 'ws_CO_MSI_3',
        'phone_number'         => '254744000001',
        'amount'               => '1000.00',
        'mpesa_receipt_number' => 'QMSI333333',
        'status'               => 'successful',
    ]);

    $service = app(MpesaWebhookService::class);

    $service->processRepayment('STK', $first->id);
    $service->processRepayment('STK', $second->id);

    $latest = LoanLedgerEntry::where('loan_id', $loan->id)->latest('id')->first();

    expect(number_format((float) $latest->running_principal_balance, 2, '.', ''))->toBe('7000.00');
    expect(LoanLedgerEntry::where('loan_id', $loan->id)->where('entry_type', 'repayment')->count())->toBe(2);
});

it('answers a racing duplicate C2B callback as a normal duplicate', function (): void {
    msiLoan();

    MpesaC2bCallback::create([
        'transaction_type'    => 'Pay Utility',
        'trans_id'            => 'MSIDUPE001',
        'trans_time'          => '20261018140000',
        'trans_amount'        => '500.00',
        'business_short_code' => '174379',
        'bill_ref_number'     => 'LN-MSI-01',
        'msisdn'              => '254744000001',
        'is_reconciled'       => false,
    ]);

    $service = app(MpesaWebhookService::class);

    $service->ingestC2bConfirmation([
        'TransID'       => 'MSIDUPE001',
        'TransAmount'   => '500.00',
        'BillRefNumber' => 'LN-MSI-01',
        'MSISDN'        => '254744000001',
    ]);
})->throws(\App\Exceptions\Fintech\DuplicateWebhookException::class);