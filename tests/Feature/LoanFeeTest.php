<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Enums\UserRole;
use App\Models\AuditTrail;
use App\Models\Borrower;
use App\Models\ChartOfAccount;
use App\Models\FeeMatrix;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanLedgerEntry;
use App\Models\LoanProduct;
use App\Models\MpesaB2cDisbursement;
use App\Models\ProductInterestRule;
use App\Models\TaxWithholdingLedger;
use App\Models\User;
use App\Services\Financial\Mpesa\B2cDisbursementService;
use App\Services\Lending\LoanDisbursementWorkflowService;
use App\Services\Lending\LoanFeeService;
use App\Services\Lending\LoanOriginationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @param array{type: string, amount: string, pct: string, excise: bool}|null $fee
 * @return array{application: LoanApplication, user: User, loan: Loan|null}
 */
function lfeSetup(
    bool $fullLedger = true,
    ?array $fee = ['type' => 'percentage', 'amount' => '0.00', 'pct' => '5.00', 'excise' => true],
    bool $withLoan = true,
    string $requested = '5000.00'
): array {
    ChartOfAccount::create(['account_code' => '1010', 'account_name' => 'M-Pesa Settlement Account', 'account_type' => 'ASSET', 'currency' => 'KES', 'is_active' => true]);
    ChartOfAccount::create(['account_code' => '1200', 'account_name' => 'Loans Receivable', 'account_type' => 'ASSET', 'currency' => 'KES', 'is_active' => true]);

    if ($fullLedger) {
        ChartOfAccount::create(['account_code' => '2030', 'account_name' => 'KRA Excise Duty Payable', 'account_type' => 'LIABILITY', 'currency' => 'KES', 'is_active' => true]);
        ChartOfAccount::create(['account_code' => '4020', 'account_name' => 'Fee Income', 'account_type' => 'REVENUE', 'currency' => 'KES', 'is_active' => true]);
    }

    $user = User::create([
        'name'         => 'Lfe Tester',
        'email'        => 'lfe-user@ethical-lending.test',
        'password'     => 'Demo@12345',
        'phone_number' => '254700000077',
        'is_active'    => true,
    ]);

    $borrower = Borrower::create([
        'full_name'      => 'Lfe Borrower',
        'id_number'      => '42333444',
        'phone_number'   => '254712000333',
        'credit_limit'   => '15000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::create([
        'name'                        => 'Lfe Product',
        'product_code'                => 'LFE-30',
        'min_principal'               => '1000.00',
        'max_principal'               => '50000.00',
        'default_tenure_days'         => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active'                   => true,
    ]);

    ProductInterestRule::create([
        'loan_product_id'       => $product->id,
        'tier_name'             => 'Standard',
        'annual_rate'           => '18.00',
        'monthly_rate'          => '1.50',
        'compounding_frequency' => 'none',
    ]);

    if ($fee !== null) {
        FeeMatrix::create([
            'loan_product_id'           => $product->id,
            'fee_name'                  => 'Processing fee',
            'calculation_type'          => $fee['type'],
            'fee_amount'                => $fee['amount'],
            'fee_percentage'            => $fee['pct'],
            'is_excise_duty_applicable' => $fee['excise'],
        ]);
    }

    $application = LoanApplication::create([
        'application_number'    => 'APP-LFE-01',
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => $product->id,
        'requested_principal'   => $requested,
        'requested_tenure_days' => 30,
        'status'                => 'approved',
    ]);

    $loan = $withLoan
        ? app(LoanOriginationService::class)->originate($application->id, $user->id, '127.0.0.1', 'Pest')
        : null;

    return ['application' => $application, 'user' => $user, 'loan' => $loan];
}

function lfeComplete(Loan $loan): void
{
    MpesaB2cDisbursement::query()->firstOrCreate(
        ['loan_id' => $loan->id, 'originator_conversation_id' => 'B2C-LFE-1'],
        [
            'conversation_id' => 'CONV-LFE-1',
            'recipient_phone' => '254712000333',
            'amount'          => (string) $loan->principal_disbursed,
            'status'          => 'initiated',
        ]
    );

    app(B2cDisbursementService::class)->processCallback([
        'Result' => [
            'ResultCode'               => 0,
            'ResultDesc'               => 'The service request is processed successfully.',
            'OriginatorConversationID' => 'B2C-LFE-1',
            'ConversationID'           => 'CONV-LFE-1',
            'TransactionID'            => 'LFETXN001',
        ],
    ]);
}

function lfeAdmin(): User
{
    Role::findOrCreate(UserRole::LenderAdmin->value, 'web');

    $user = User::create([
        'name'         => 'Lfe Admin',
        'email'        => 'lfe-admin@ethical-lending.test',
        'password'     => 'Demo@12345',
        'phone_number' => '254700000078',
        'is_active'    => true,
    ]);
    $user->assignRole(UserRole::LenderAdmin->value);

    return $user;
}

function lfeAccountTotal(string $code, string $column): string
{
    $account = ChartOfAccount::query()->where('account_code', $code)->firstOrFail();

    return number_format((float) $account->journalItems()->sum($column), 2, '.', '');
}

it('rounds a percentage fee half up to the cent', function (): void {
    $s = lfeSetup(fee: ['type' => 'percentage', 'amount' => '0.00', 'pct' => '1.50', 'excise' => true], requested: '1001.00');

    expect(app(LoanFeeService::class)->quote($s['loan']))->toBe([
        ['name' => 'Processing fee', 'amount' => '15.02', 'excise' => true],
    ]);
});

it('uses the set amount for a fixed fee', function (): void {
    $s = lfeSetup(fee: ['type' => 'fixed', 'amount' => '100.00', 'pct' => '0.00', 'excise' => false]);

    expect(app(LoanFeeService::class)->quote($s['loan']))->toBe([
        ['name' => 'Processing fee', 'amount' => '100.00', 'excise' => false],
    ]);
});

it('skips a fee type it does not understand', function (): void {
    $s = lfeSetup(fee: ['type' => 'banana', 'amount' => '100.00', 'pct' => '5.00', 'excise' => true]);

    expect(app(LoanFeeService::class)->quote($s['loan']))->toBe([]);
});

it('adds the fee on top of the principal when the money lands', function (): void {
    $s = lfeSetup();

    lfeComplete($s['loan']);

    $latest = LoanLedgerEntry::query()->where('loan_id', $s['loan']->id)->latest('id')->first();

    expect(LoanLedgerEntry::query()->where('loan_id', $s['loan']->id)->count())->toBe(2)
        ->and($latest->entry_type)->toBe('FEE_CHARGED')
        ->and((string) $latest->running_principal_balance)->toBe('5000.00')
        ->and((string) $latest->running_fees_balance)->toBe('250.00')
        ->and((string) $latest->total_running_balance)->toBe('5250.00');
});

it('splits the fee 80/20 in the general ledger and records the excise for KRA', function (): void {
    $s = lfeSetup();

    lfeComplete($s['loan']);

    $tax = TaxWithholdingLedger::query()->where('loan_id', $s['loan']->id)->first();

    expect($tax)->not->toBeNull()
        ->and((string) $tax->fee_charged)->toBe('250.00')
        ->and((string) $tax->excise_duty_amount)->toBe('50.00')
        ->and(lfeAccountTotal('4020', 'credit'))->toBe('200.00')
        ->and(lfeAccountTotal('2030', 'credit'))->toBe('50.00');
});

it('books a fee without excise straight to fee income', function (): void {
    $s = lfeSetup(fee: ['type' => 'percentage', 'amount' => '0.00', 'pct' => '5.00', 'excise' => false]);

    lfeComplete($s['loan']);

    $latest = LoanLedgerEntry::query()->where('loan_id', $s['loan']->id)->latest('id')->first();

    expect(TaxWithholdingLedger::query()->where('loan_id', $s['loan']->id)->count())->toBe(0)
        ->and((string) $latest->running_fees_balance)->toBe('250.00')
        ->and(lfeAccountTotal('4020', 'credit'))->toBe('250.00');
});

it('charges the fee only once when the same callback arrives twice', function (): void {
    $s = lfeSetup();

    lfeComplete($s['loan']);
    lfeComplete($s['loan']);

    expect(LoanLedgerEntry::query()->where('loan_id', $s['loan']->id)->where('entry_type', 'FEE_CHARGED')->count())->toBe(1)
        ->and(TaxWithholdingLedger::query()->where('loan_id', $s['loan']->id)->count())->toBe(1);
});

it('still completes the disbursement when the fee cannot be booked', function (): void {
    $s = lfeSetup(fullLedger: false);

    lfeComplete($s['loan']);

    $loan = $s['loan']->fresh();

    expect($loan->status)->toBe(LoanStatus::DISBURSED)
        ->and(LoanLedgerEntry::query()->where('loan_id', $loan->id)->count())->toBe(1)
        ->and(LoanLedgerEntry::query()->where('loan_id', $loan->id)->where('entry_type', 'FEE_CHARGED')->count())->toBe(0)
        ->and(AuditTrail::query()->where('action', 'LOAN_FEE_CHARGE_FAILED')->where('auditable_id', $loan->id)->exists())->toBeTrue();
});

it('refuses to send money when the ledger accounts are missing', function (): void {
    Http::fake();
    $s = lfeSetup(fullLedger: false, withLoan: false);

    expect(fn () => app(LoanDisbursementWorkflowService::class)
        ->disburse($s['application']->id, $s['user']->id, '127.0.0.1', 'Pest'))
        ->toThrow(RuntimeException::class, 'The ledger is missing accounts');

    Http::assertNothingSent();
    expect(MpesaB2cDisbursement::query()->count())->toBe(0);
});

it('shows staff the fee and the total owed before the money is sent', function (): void {
    $this->withoutVite();
    $s = lfeSetup();

    $this->actingAs(lfeAdmin())
        ->get(route('lender.applications.show', ['loanApplication' => $s['application']->id]))
        ->assertOk()
        ->assertSee('Charges added when the money lands')
        ->assertSee('Processing fee')
        ->assertSee('KES 250.00')
        ->assertSee('KES 5,250.00');
});

it('shows staff the fee that was added once the money is sent', function (): void {
    $this->withoutVite();
    $s = lfeSetup();

    lfeComplete($s['loan']);

    $this->actingAs(lfeAdmin())
        ->get(route('lender.applications.show', ['loanApplication' => $s['application']->id]))
        ->assertOk()
        ->assertSee('Money sent to the borrower.')
        ->assertSee('Fees added to the balance')
        ->assertSee('KES 250.00');
});