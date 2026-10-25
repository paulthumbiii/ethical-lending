<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Models\AuditTrail;
use App\Models\Borrower;
use App\Models\ChartOfAccount;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanLedgerEntry;
use App\Models\LoanProduct;
use App\Models\MpesaB2cDisbursement;
use App\Models\OutboxEvent;
use App\Models\ProductInterestRule;
use App\Models\User;
use App\Services\Financial\Mpesa\B2cDisbursementService;
use App\Services\Lending\LoanOriginationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{application: LoanApplication, borrower: Borrower, product: LoanProduct, user: User}
 */
function lorSetup(string $limit = '15000.00', string $requested = '5000.00', string $status = 'approved', bool $withRate = true): array
{
    ChartOfAccount::create(['account_code' => '1010', 'account_name' => 'M-Pesa Settlement Account', 'account_type' => 'ASSET', 'currency' => 'KES', 'is_active' => true]);
    ChartOfAccount::create(['account_code' => '1200', 'account_name' => 'Loans Receivable', 'account_type' => 'ASSET', 'currency' => 'KES', 'is_active' => true]);

    $user = User::create([
        'name'         => 'Origination Tester',
        'email'        => 'lor-user@ethical-lending.test',
        'password'     => 'Demo@12345',
        'phone_number' => '254700000099',
        'is_active'    => true,
    ]);

    $borrower = Borrower::create([
        'full_name'      => 'Lor Borrower',
        'id_number'      => '40111222',
        'phone_number'   => '254712000111',
        'credit_limit'   => $limit,
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::create([
        'name'                        => 'Lor Product',
        'product_code'                => 'LOR-30',
        'min_principal'               => '1000.00',
        'max_principal'               => '50000.00',
        'default_tenure_days'         => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active'                   => true,
    ]);

    if ($withRate) {
        ProductInterestRule::create([
            'loan_product_id'       => $product->id,
            'tier_name'             => 'Standard',
            'annual_rate'           => '18.00',
            'monthly_rate'          => '1.50',
            'compounding_frequency' => 'none',
        ]);
    }

    $application = LoanApplication::create([
        'application_number'    => 'APP-LOR-01',
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => $product->id,
        'requested_principal'   => $requested,
        'requested_tenure_days' => 30,
        'status'                => $status,
    ]);

    return ['application' => $application, 'borrower' => $borrower, 'product' => $product, 'user' => $user];
}

function lorOriginate(array $s): Loan
{
    return app(LoanOriginationService::class)->originate($s['application']->id, $s['user']->id, '127.0.0.1', 'Pest');
}

function lorFakeDaraja(): void
{
    Http::fake([
        '*/oauth/v1/generate*'           => Http::response(['access_token' => 'TEST_TOKEN', 'expires_in' => 3599], 200),
        '*/mpesa/b2c/v1/paymentrequest*' => Http::response([
            'ConversationID'      => 'AG_LOR_001',
            'ResponseCode'        => '0',
            'ResponseDescription' => 'Accept the service request successfully.',
        ], 200),
    ]);
}

function lorSentDisbursement(Loan $loan, string $originator = 'B2C-LOR-ORIG-1', string $conversation = 'CONV-LOR-1'): MpesaB2cDisbursement
{
    return MpesaB2cDisbursement::create([
        'loan_id'                    => $loan->id,
        'originator_conversation_id' => $originator,
        'conversation_id'            => $conversation,
        'recipient_phone'            => '254712000111',
        'amount'                     => (string) $loan->principal_disbursed,
        'status'                     => 'initiated',
    ]);
}

function lorSuccessPayload(string $originator = 'B2C-LOR-ORIG-1', string $conversation = 'CONV-LOR-1'): array
{
    return [
        'Result' => [
            'ResultCode'               => 0,
            'ResultDesc'               => 'The service request is processed successfully.',
            'OriginatorConversationID' => $originator,
            'ConversationID'           => $conversation,
            'TransactionID'            => 'LORTXN001',
        ],
    ];
}

it('creates a loan in approval hold with the product rate and a capped principal', function (): void {
    $s = lorSetup(limit: '3750.00', requested: '5000.00');

    $loan = lorOriginate($s);

    expect($loan->status)->toBe(LoanStatus::APPROVAL_HOLD)
        ->and(number_format((float) $loan->principal_disbursed, 2, '.', ''))->toBe('3750.00')
        ->and(number_format((float) $loan->annual_interest_rate, 2, '.', ''))->toBe('18.00')
        ->and($loan->tenure_days)->toBe(30)
        ->and($loan->due_date)->not->toBeNull()
        ->and($loan->disbursed_at)->toBeNull();

    expect(AuditTrail::query()->where('action', 'LOAN_CREATED')->where('auditable_id', $loan->id)->exists())->toBeTrue();
});

it('uses the requested amount when the credit limit is higher', function (): void {
    $s = lorSetup(limit: '15000.00', requested: '5000.00');

    $loan = lorOriginate($s);

    expect(number_format((float) $loan->principal_disbursed, 2, '.', ''))->toBe('5000.00');
});

it('refuses an application that is not approved', function (): void {
    $s = lorSetup(status: 'approved_pending_checker');

    lorOriginate($s);
})->throws(RuntimeException::class, 'Only an approved application can become a loan.');

it('refuses to create a second loan for the same application', function (): void {
    $s = lorSetup();
    lorOriginate($s);

    lorOriginate($s);
})->throws(RuntimeException::class, 'A loan has already been created for this application.');

it('refuses a product that has no interest rate', function (): void {
    $s = lorSetup(withRate: false);

    lorOriginate($s);
})->throws(RuntimeException::class, 'This product has no interest rate set up.');

it('blocks a second disbursement while the first is initiated', function (): void {
    lorFakeDaraja();
    $s = lorSetup();
    $loan = lorOriginate($s);
    $service = app(B2cDisbursementService::class);

    $service->initiateDisbursement($loan);

    expect(fn () => $service->initiateDisbursement($loan))
        ->toThrow(RuntimeException::class, 'Money has already been sent, or is being sent, for this loan.');

    expect(MpesaB2cDisbursement::query()->where('loan_id', $loan->id)->count())->toBe(1);
});

it('allows a retry after an earlier attempt failed', function (): void {
    lorFakeDaraja();
    $s = lorSetup();
    $loan = lorOriginate($s);

    MpesaB2cDisbursement::create([
        'loan_id'         => $loan->id,
        'recipient_phone' => '254712000111',
        'amount'          => (string) $loan->principal_disbursed,
        'status'          => 'failed',
    ]);

    $second = app(B2cDisbursementService::class)->initiateDisbursement($loan);

    expect($second->status)->toBe('initiated')
        ->and(MpesaB2cDisbursement::query()->where('loan_id', $loan->id)->count())->toBe(2);
});

it('does not post the ledger twice when the same success callback arrives again', function (): void {
    $s = lorSetup();
    $loan = lorOriginate($s);
    lorSentDisbursement($loan);
    $service = app(B2cDisbursementService::class);

    $service->processCallback(lorSuccessPayload());
    $service->processCallback(lorSuccessPayload());

    expect(LoanLedgerEntry::query()->where('loan_id', $loan->id)->count())->toBe(1)
        ->and(OutboxEvent::query()->where('aggregate_id', $loan->id)->where('event_name', 'loan.disbursed')->count())->toBe(1);

    $loan->refresh();
    expect($loan->status)->toBe(LoanStatus::DISBURSED);
});

it('rejects a callback that carries no conversation id', function (): void {
    $s = lorSetup();
    $loan = lorOriginate($s);
    lorSentDisbursement($loan);

    app(B2cDisbursementService::class)->processCallback(['Result' => ['ResultCode' => 0]]);
})->throws(RuntimeException::class, 'The callback has no conversation id.');

it('starts the due date from the day the money is delivered', function (): void {
    $s = lorSetup();
    $loan = lorOriginate($s);
    lorSentDisbursement($loan);

    $this->travelTo(now()->addDays(2));
    app(B2cDisbursementService::class)->processCallback(lorSuccessPayload());

    $loan->refresh();
    expect($loan->due_date->isSameDay(now()->addDays(30)))->toBeTrue();
});

it('completes a disbursement through the b2c result route', function (): void {
    $s = lorSetup();
    $loan = lorOriginate($s);
    lorSentDisbursement($loan);

    $this->postJson('/api/v1/mpesa/b2c-result', lorSuccessPayload())
        ->assertOk()
        ->assertJson(['ResultCode' => 0]);

    $loan->refresh();
    expect($loan->status)->toBe(LoanStatus::DISBURSED);
});

it('acknowledges a b2c timeout and leaves the disbursement initiated', function (): void {
    $s = lorSetup();
    $loan = lorOriginate($s);
    $disbursement = lorSentDisbursement($loan);

    $this->postJson('/api/v1/mpesa/b2c-timeout', lorSuccessPayload())
        ->assertOk()
        ->assertJson(['ResultCode' => 0]);

    expect($disbursement->fresh()->status)->toBe('initiated');

    $loan->refresh();
    expect($loan->status)->toBe(LoanStatus::APPROVAL_HOLD);
});