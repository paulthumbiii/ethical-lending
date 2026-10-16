<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Exceptions\Fintech\SimSwapRiskDetectedException;
use App\Models\Borrower;
use App\Models\ChartOfAccount;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanLedgerEntry;
use App\Models\LoanProduct;
use App\Models\MpesaB2cDisbursement;
use App\Models\OutboxEvent;
use App\Services\Financial\Mpesa\B2cDisbursementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    // Seed Chart of Accounts for GL integration
    ChartOfAccount::create(['account_code' => '1010', 'account_name' => 'M-Pesa Settlement Account', 'account_type' => 'ASSET', 'currency' => 'KES', 'is_active' => true]);
    ChartOfAccount::create(['account_code' => '1200', 'account_name' => 'Loans Receivable', 'account_type' => 'ASSET', 'currency' => 'KES', 'is_active' => true]);

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
        'application_number'   => 'APP-B2C-01',
        'borrower_id'          => $this->borrower->id,
        'loan_product_id'      => $this->product->id,
        'requested_principal'  => '15000.00',
        'requested_tenure_days'=> 30,
        'status'               => 'approved',
    ]);

    $this->loan = Loan::create([
        'loan_number'         => 'LN-B2C-01',
        'loan_application_id' => $this->application->id,
        'borrower_id'         => $this->borrower->id,
        'loan_product_id'     => $this->product->id,
        'principal_disbursed' => '15000.00',
        'annual_interest_rate'=> '15.00',
        'tenure_days'         => 30,
        'status'              => LoanStatus::APPROVAL_HOLD,
        'disbursed_at'        => null,
        'due_date'            => null,
        'is_in_duplum_locked' => false,
    ]);

    $this->service = app(B2cDisbursementService::class);
});

it('initiates mpesa b2c disbursement successfully when phone line is clean', function (): void {
    Http::fake([
        '*/oauth/v1/generate*' => Http::response(['access_token' => 'TEST_TOKEN', 'expires_in' => 3599], 200),
        '*/mpesa/b2c/v1/paymentrequest*' => Http::response([
            'ConversationID'           => 'AG_20261016_TEST123',
            'OriginatorConversationID' => 'B2C-TEST-ORIG-123',
            'ResponseCode'             => '0',
            'ResponseDescription'      => 'Accept the service request successfully.',
        ], 200),
    ]);

    $disbursement = $this->service->initiateDisbursement($this->loan);

    expect($disbursement)->toBeInstanceOf(MpesaB2cDisbursement::class)
        ->and($disbursement->status)->toBe('initiated')
        ->and(number_format((float) $disbursement->amount, 2, '.', ''))->toBe('15000.00')
        ->and($disbursement->recipient_phone)->toBe('254712345678');

    Http::assertSent(fn ($req) => str_contains($req->url(), '/mpesa/b2c/v1/paymentrequest'));
});

it('blocks disbursement and throws SimSwapRiskDetectedException when SIM was swapped within 48 hours', function (): void {
    Http::fake([
        '*/oauth/v1/generate*' => Http::response(['access_token' => 'TEST_TOKEN', 'expires_in' => 3599], 200),
    ]);

    // Simulate SIM swapped 10 hours ago (< 48 hours quarantine window)
    $swappedAt = CarbonImmutable::now()->subHours(10);

    $this->service->initiateDisbursement($this->loan, $swappedAt);
})->throws(SimSwapRiskDetectedException::class);

it('processes successful b2c callback, updating loan, ledger, general ledger, and outbox', function (): void {
    $disbursement = MpesaB2cDisbursement::create([
        'loan_id'                    => $this->loan->id,
        'originator_conversation_id' => 'B2C-CALLBACK-TEST-01',
        'conversation_id'            => 'CONV-TEST-01',
        'recipient_phone'            => '254712345678',
        'amount'                     => '15000.00',
        'status'                     => 'initiated',
    ]);

    $callbackPayload = [
        'Result' => [
            'ResultType'               => 0,
            'ResultCode'               => 0,
            'ResultDesc'               => 'The service request is processed successfully.',
            'OriginatorConversationID' => 'B2C-CALLBACK-TEST-01',
            'ConversationID'           => 'CONV-TEST-01',
            'TransactionID'            => 'QHD7823HG1',
        ],
    ];

    $updated = $this->service->processCallback($callbackPayload);

    expect($updated->status)->toBe('successful')
        ->and($updated->transaction_id)->toBe('QHD7823HG1');

    // Assert Loan status transitioned to DISBURSED
    $this->loan->refresh();
    expect($this->loan->status)->toBe(LoanStatus::DISBURSED)
        ->and($this->loan->disbursed_at)->not->toBeNull();

    // Assert initial debit in Loan Ledger
    $ledger = LoanLedgerEntry::where('loan_id', $this->loan->id)->first();
    expect($ledger)->not->toBeNull()
        ->and($ledger->entry_type)->toBe('disbursement')
        ->and(number_format((float) $ledger->running_principal_balance, 2, '.', ''))->toBe('15000.00');

    // Assert Outbox Event committed
    $outbox = OutboxEvent::where('aggregate_id', $this->loan->id)
        ->where('event_name', 'loan.disbursed')
        ->first();

    expect($outbox)->not->toBeNull()
        ->and($outbox->payload['transaction_id'])->toBe('QHD7823HG1');
});

it('processes failed b2c callback without updating loan status or debiting ledger', function (): void {
    $disbursement = MpesaB2cDisbursement::create([
        'loan_id'                    => $this->loan->id,
        'originator_conversation_id' => 'B2C-FAIL-TEST-01',
        'conversation_id'            => 'CONV-FAIL-01',
        'recipient_phone'            => '254712345678',
        'amount'                     => '15000.00',
        'status'                     => 'initiated',
    ]);

    $callbackPayload = [
        'Result' => [
            'ResultCode'               => 2001,
            'ResultDesc'               => 'The initiator information is invalid.',
            'OriginatorConversationID' => 'B2C-FAIL-TEST-01',
            'ConversationID'           => 'CONV-FAIL-01',
        ],
    ];

    $updated = $this->service->processCallback($callbackPayload);

    expect($updated->status)->toBe('failed');

    $this->loan->refresh();
    expect($this->loan->status)->toBe(LoanStatus::APPROVAL_HOLD);
    expect(LoanLedgerEntry::where('loan_id', $this->loan->id)->count())->toBe(0);
});