<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Models\Borrower;
use App\Models\ChartOfAccount;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\MpesaB2cDisbursement;
use App\Models\MpesaC2bCallback;
use App\Models\MpesaStkRequest;
use App\Models\TaxWithholdingLedger;
use App\Models\User;
use App\Services\Financial\GeneralLedgerService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(RolesSeeder::class);
});

function fnvUser(string $role): User
{
    static $n = 0;
    $n++;

    $user = User::create([
        'name'         => ucfirst($role),
        'email'        => $role . $n . '@fnv.test',
        'password'     => 'Demo@12345',
        'phone_number' => '2547' . sprintf('%08d', 9000000 + $n),
        'is_active'    => true,
    ]);
    $user->assignRole($role);

    return $user;
}

function fnvAccounts(): void
{
    foreach ([
        ['1010', 'M-Pesa Settlement Account', 'ASSET'],
        ['1200', 'Loans Receivable', 'ASSET'],
        ['2030', 'KRA Excise Duty Payable', 'LIABILITY'],
        ['4010', 'Interest Income', 'REVENUE'],
    ] as [$code, $name, $type]) {
        ChartOfAccount::create([
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
            'currency'     => 'KES',
            'is_active'    => true,
        ]);
    }
}

function fnvPost(string $description, string $amount): string
{
    $entry = app(GeneralLedgerService::class)->postBalancedEntry(
        description: $description,
        items: [
            ['account_code' => '1010', 'debit' => $amount, 'credit' => '0.00'],
            ['account_code' => '4010', 'debit' => '0.00', 'credit' => $amount],
        ]
    );

    return $entry->entry_number;
}

function fnvLoan(): Loan
{
    $borrower = Borrower::create([
        'full_name'      => 'Finance Test Borrower',
        'id_number'      => '30000001',
        'phone_number'   => '254711000001',
        'credit_limit'   => '10000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::create([
        'name'                        => 'Finance Test Product',
        'product_code'                => 'FNV-30',
        'min_principal'               => '1000.00',
        'max_principal'               => '50000.00',
        'default_tenure_days'         => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active'                   => true,
    ]);

    $application = LoanApplication::create([
        'application_number'    => 'APP-FNV-01',
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => $product->id,
        'requested_principal'   => '5000.00',
        'requested_tenure_days' => 30,
        'status'                => 'approved',
    ]);

    return Loan::create([
        'loan_number'          => 'LN-FNV-01',
        'loan_application_id'  => $application->id,
        'borrower_id'          => $borrower->id,
        'loan_product_id'      => $product->id,
        'principal_disbursed'  => '5000.00',
        'annual_interest_rate' => '15.00',
        'tenure_days'          => 30,
        'status'               => LoanStatus::DISBURSED,
        'disbursed_at'         => CarbonImmutable::now(),
        'due_date'             => CarbonImmutable::now()->addDays(30),
        'is_in_duplum_locked'  => false,
    ]);
}

it('lets a finance officer open the finance page', function (): void {
    $this->actingAs(fnvUser('finance_officer'))
        ->get('/lender/finance')
        ->assertOk()
        ->assertSee('Trial balance');
});

it('lets an auditor open the finance page', function (): void {
    $this->actingAs(fnvUser('auditor'))
        ->get('/lender/finance')
        ->assertOk();
});

it('blocks a support agent from the finance page', function (): void {
    $this->actingAs(fnvUser('support_agent'))
        ->get('/lender/finance')
        ->assertForbidden();
});

it('sends a guest to the login page', function (): void {
    $this->get('/lender/finance')->assertRedirect('/login');
});

it('lists journal entries with their lines', function (): void {
    fnvAccounts();
    $number = fnvPost('Finance view test posting', '5000.00');

    $this->actingAs(fnvUser('finance_officer'))
        ->get('/lender/finance?tab=journal')
        ->assertOk()
        ->assertSee($number)
        ->assertSee('Finance view test posting')
        ->assertSee('M-Pesa Settlement Account')
        ->assertSee('Dr 5,000.00')
        ->assertSee('Cr 5,000.00');
});

it('filters the journal by date', function (): void {
    fnvAccounts();
    $number = fnvPost('Should be filtered out', '1000.00');
    $tomorrow = CarbonImmutable::now()->addDay()->toDateString();

    $this->actingAs(fnvUser('finance_officer'))
        ->get('/lender/finance?tab=journal&from=' . $tomorrow)
        ->assertOk()
        ->assertDontSee($number)
        ->assertSee('No journal entries');
});

it('shows a balanced trial balance with BCMath totals', function (): void {
    fnvAccounts();
    fnvPost('First', '5000.00');
    fnvPost('Second', '2500.00');

    $this->actingAs(fnvUser('auditor'))
        ->get('/lender/finance?tab=trial')
        ->assertOk()
        ->assertSee('Balanced')
        ->assertSee('7,500.00');
});

it('shows excise owed, remitted and the ledger tie-out', function (): void {
    $loan = fnvLoan();

    TaxWithholdingLedger::create([
        'loan_id'            => $loan->id,
        'fee_name'           => 'Processing fee',
        'fee_charged'        => '1000.00',
        'excise_rate'        => '20.00',
        'excise_duty_amount' => '200.00',
        'remittance_status'  => 'accrued',
        'remitted_at'        => null,
    ]);
    TaxWithholdingLedger::create([
        'loan_id'            => $loan->id,
        'fee_name'           => 'Late fee',
        'fee_charged'        => '500.00',
        'excise_rate'        => '20.00',
        'excise_duty_amount' => '100.00',
        'remittance_status'  => 'remitted',
        'remitted_at'        => CarbonImmutable::now(),
    ]);

    $this->actingAs(fnvUser('finance_officer'))
        ->get('/lender/finance?tab=excise')
        ->assertOk()
        ->assertSee('LN-FNV-01')
        ->assertSee('KES 200.00')
        ->assertSee('KES 100.00')
        ->assertSee('KES 300.00')
        ->assertSee('Does not tie out');
});

it('shows C2B, STK and B2C records on the M-Pesa tab', function (): void {
    $loan = fnvLoan();

    MpesaC2bCallback::create([
        'transaction_type'    => 'Pay Utility',
        'trans_id'            => 'FNVC2B001',
        'trans_time'          => '20261024100000',
        'trans_amount'        => '3000.00',
        'business_short_code' => '174379',
        'bill_ref_number'     => 'LN-FNV-01',
        'msisdn'              => '254711000001',
        'is_reconciled'       => false,
    ]);

    MpesaStkRequest::create([
        'loan_id'              => $loan->id,
        'merchant_request_id'  => 'MERCH-FNV',
        'checkout_request_id'  => 'ws_CO_FNV_1',
        'phone_number'         => '254711000001',
        'amount'               => '1500.00',
        'mpesa_receipt_number' => 'FNVSTK001',
        'status'               => 'successful',
    ]);

    MpesaB2cDisbursement::forceCreate([
        'loan_id'         => $loan->id,
        'recipient_phone' => '254711000001',
        'amount'          => '5000.00',
        'status'          => 'successful',
        'transaction_id'  => 'FNVB2C001',
    ]);

    $user = fnvUser('finance_officer');

    $this->actingAs($user)->get('/lender/finance?tab=mpesa&source=c2b')
        ->assertOk()->assertSee('FNVC2B001');

    $this->actingAs($user)->get('/lender/finance?tab=mpesa&source=stk')
        ->assertOk()->assertSee('FNVSTK001');

    $this->actingAs($user)->get('/lender/finance?tab=mpesa&source=b2c')
        ->assertOk()->assertSee('FNVB2C001');
});

it('rejects an unknown tab', function (): void {
    $this->actingAs(fnvUser('finance_officer'))
        ->get('/lender/finance?tab=bogus')
        ->assertSessionHasErrors('tab');
});