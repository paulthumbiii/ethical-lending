<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Enums\UserRole;
use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\ChartOfAccount;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanLedgerEntry;
use App\Models\LoanProduct;
use App\Models\MpesaB2cDisbursement;
use App\Models\PaymentWaterfallLog;
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

function ldrUser(UserRole $role): User
{
    static $n = 0;
    $n++;

    $user = User::create([
        'name'         => 'Loan Dir ' . $n,
        'email'        => "ldr{$n}@example.test",
        'password'     => 'secret-pass-1',
        'phone_number' => '2547003' . str_pad((string) $n, 5, '0', STR_PAD_LEFT),
        'is_active'    => true,
    ]);
    $user->assignRole($role->value);

    return $user;
}

function ldrLoan(string $number, string $borrowerName = 'Loan Owner', LoanStatus $status = LoanStatus::DISBURSED, array $overrides = []): Loan
{
    static $n = 0;
    $n++;

    $borrower = Borrower::create([
        'full_name'      => $borrowerName,
        'id_number'      => '3200' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        'phone_number'   => '2547550' . str_pad((string) $n, 5, '0', STR_PAD_LEFT),
        'credit_limit'   => '1125.00',
        'risk_band'      => RiskBand::C,
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::firstOrCreate(['product_code' => 'LDR-30'], [
        'name'                        => 'Loan Dir Product',
        'min_principal'               => '1000.00',
        'max_principal'               => '20000.00',
        'default_tenure_days'         => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active'                   => true,
    ]);

    $application = LoanApplication::create([
        'application_number'    => 'APP-' . $number,
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => $product->id,
        'requested_principal'   => '1500.00',
        'requested_tenure_days' => 30,
        'status'                => 'approved',
    ]);

    return Loan::create(array_merge([
        'loan_number'          => $number,
        'loan_application_id'  => $application->id,
        'borrower_id'          => $borrower->id,
        'loan_product_id'      => $product->id,
        'principal_disbursed'  => '1125.00',
        'annual_interest_rate' => '18.00',
        'tenure_days'          => 30,
        'status'               => $status,
        'disbursed_at'         => CarbonImmutable::now()->subDays(5),
        'due_date'             => CarbonImmutable::now()->addDays(25),
        'is_in_duplum_locked'  => false,
    ], $overrides));
}

/** Disbursement of 1,125 and a 56.25 fee, leaving 1,181.25 owed. */
function ldrLedger(Loan $loan): void
{
    LoanLedgerEntry::create([
        'loan_id'                   => $loan->id,
        'entry_type'                => 'disbursement',
        'debit'                     => '1125.00',
        'credit'                    => '0.00',
        'running_principal_balance' => '1125.00',
        'running_interest_balance'  => '0.00',
        'running_fees_balance'      => '0.00',
        'total_running_balance'     => '1125.00',
        'reference'                 => 'DISB-' . $loan->loan_number,
    ]);

    LoanLedgerEntry::create([
        'loan_id'                   => $loan->id,
        'entry_type'                => 'FEE_CHARGED',
        'debit'                     => '56.25',
        'credit'                    => '0.00',
        'running_principal_balance' => '1125.00',
        'running_interest_balance'  => '0.00',
        'running_fees_balance'      => '56.25',
        'total_running_balance'     => '1181.25',
        'reference'                 => 'FEE-' . $loan->loan_number,
    ]);
}

it('sends guests to the login page', function (): void {
    $this->get(route('lender.loans.index'))->assertRedirect(route('login'));
});

it('keeps borrowers out of the loan list and loan page', function (): void {
    $loan = ldrLoan('LN-LDR-001');
    $borrowerUser = ldrUser(UserRole::Borrower);

    $this->actingAs($borrowerUser)->get(route('lender.loans.index'))->assertForbidden();
    $this->actingAs($borrowerUser)->get(route('lender.loans.show', $loan))->assertForbidden();
});

it('lets every staff role open the loan list', function (): void {
    ldrLoan('LN-LDR-002');

    foreach (UserRole::staff() as $role) {
        $this->actingAs(ldrUser(UserRole::from($role)))
            ->get(route('lender.loans.index'))
            ->assertOk()
            ->assertSee('LN-LDR-002');
    }
});

it('shows the latest ledger balance on the list', function (): void {
    $loan = ldrLoan('LN-LDR-003');
    ldrLedger($loan);

    $this->actingAs(ldrUser(UserRole::SupportAgent))
        ->get(route('lender.loans.index'))
        ->assertOk()
        ->assertSee('LN-LDR-003')
        ->assertSee('KES 1,181.25');
});

it('searches by loan number and borrower and shows an empty state', function (): void {
    ldrLoan('LN-LDR-AAA', 'Amina Wanjiku');
    ldrLoan('LN-LDR-BBB', 'Brian Otieno');
    $viewer = ldrUser(UserRole::Auditor);

    $this->actingAs($viewer)->get(route('lender.loans.index', ['q' => 'LN-LDR-AAA']))
        ->assertOk()->assertSee('LN-LDR-AAA')->assertDontSee('LN-LDR-BBB');

    $this->actingAs($viewer)->get(route('lender.loans.index', ['q' => 'Brian']))
        ->assertOk()->assertSee('LN-LDR-BBB')->assertDontSee('LN-LDR-AAA');

    $this->actingAs($viewer)->get(route('lender.loans.index', ['q' => 'zzzz-nobody']))
        ->assertOk()->assertSee('No loans found');
});

it('filters by status and by in-duplum freeze', function (): void {
    ldrLoan('LN-LDR-OK1');
    ldrLoan('LN-LDR-DEF', 'Defaulter', LoanStatus::DEFAULTED, ['is_in_duplum_locked' => true]);
    $viewer = ldrUser(UserRole::LenderAdmin);

    $this->actingAs($viewer)->get(route('lender.loans.index', ['status' => 'defaulted']))
        ->assertOk()->assertSee('LN-LDR-DEF')->assertDontSee('LN-LDR-OK1');

    $this->actingAs($viewer)->get(route('lender.loans.index', ['frozen' => '1']))
        ->assertOk()->assertSee('LN-LDR-DEF')->assertDontSee('LN-LDR-OK1');
});

it('shows balances, payout status and the in-duplum state on the overview', function (): void {
    $loan = ldrLoan('LN-LDR-004');
    ldrLedger($loan);
    MpesaB2cDisbursement::create([
        'loan_id'              => $loan->id,
        'recipient_phone'      => $loan->borrower->phone_number,
        'amount'               => '1125.00',
        'status'               => 'successful',
        'response_code'        => '0',
        'response_description' => 'Accepted',
        'disbursed_at'         => CarbonImmutable::now(),
    ]);

    $this->actingAs(ldrUser(UserRole::SupportAgent))
        ->get(route('lender.loans.show', $loan))
        ->assertOk()
        ->assertSee('KES 1,181.25')
        ->assertSee('Disbursed')
        ->assertSee('Successful')
        ->assertSee('Not triggered');
});

it('shows the ledger tab only to people with ledger access', function (): void {
    $loan = ldrLoan('LN-LDR-005');
    ldrLedger($loan);

    $this->actingAs(ldrUser(UserRole::Auditor))
        ->get(route('lender.loans.show', [$loan, 'tab' => 'ledger']))
        ->assertOk()
        ->assertSee('FEE_CHARGED');

    $this->actingAs(ldrUser(UserRole::SupportAgent))
        ->get(route('lender.loans.show', [$loan, 'tab' => 'ledger']))
        ->assertOk()
        ->assertDontSee('FEE_CHARGED')
        ->assertDontSee('tab=ledger');
});

it('shows repayments and how each was split on the payments tab', function (): void {
    $loan = ldrLoan('LN-LDR-006');
    PaymentWaterfallLog::create([
        'loan_id'                => $loan->id,
        'payment_reference'      => 'PAY-LDR-6',
        'total_amount_received'  => '500.00',
        'allocated_to_fees'      => '56.25',
        'allocated_to_interest'  => '20.00',
        'allocated_to_principal' => '423.75',
        'overpayment_amount'     => '0.00',
    ]);

    $this->actingAs(ldrUser(UserRole::SupportAgent))
        ->get(route('lender.loans.show', [$loan, 'tab' => 'payments']))
        ->assertOk()
        ->assertSee('PAY-LDR-6')
        ->assertSee('KES 423.75');
});

it('shows fees and excise only to people with ledger access', function (): void {
    $loan = ldrLoan('LN-LDR-007');
    TaxWithholdingLedger::create([
        'loan_id'            => $loan->id,
        'fee_name'           => 'Processing fee',
        'fee_charged'        => '56.25',
        'excise_rate'        => '20.00',
        'excise_duty_amount' => '11.25',
        'remittance_status'  => 'accrued',
    ]);

    $this->actingAs(ldrUser(UserRole::FinanceOfficer))
        ->get(route('lender.loans.show', [$loan, 'tab' => 'fees']))
        ->assertOk()
        ->assertSee('Processing fee')
        ->assertSee('KES 11.25');

    $this->actingAs(ldrUser(UserRole::SupportAgent))
        ->get(route('lender.loans.show', [$loan, 'tab' => 'fees']))
        ->assertOk()
        ->assertDontSee('Processing fee');
});

it('shows journal entries linked to the loan only to people with finance access', function (): void {
    $loan = ldrLoan('LN-LDR-008');
    ChartOfAccount::create(['account_code' => '1200', 'account_name' => 'Loans Receivable', 'account_type' => 'ASSET', 'currency' => 'KES', 'is_active' => true]);
    ChartOfAccount::create(['account_code' => '4020', 'account_name' => 'Fee Income', 'account_type' => 'REVENUE', 'currency' => 'KES', 'is_active' => true]);

    app(GeneralLedgerService::class)->postBalancedEntry(
        description: 'Journal test fee on LN-LDR-008',
        items: [
            ['account_code' => '1200', 'debit' => '56.25', 'credit' => '0.00'],
            ['account_code' => '4020', 'debit' => '0.00', 'credit' => '56.25'],
        ],
        referenceType: Loan::class,
        referenceId: $loan->id
    );

    $this->actingAs(ldrUser(UserRole::FinanceOfficer))
        ->get(route('lender.loans.show', [$loan, 'tab' => 'journal']))
        ->assertOk()
        ->assertSee('Journal test fee on LN-LDR-008')
        ->assertSee('Loans Receivable');

    $this->actingAs(ldrUser(UserRole::SupportAgent))
        ->get(route('lender.loans.show', [$loan, 'tab' => 'journal']))
        ->assertOk()
        ->assertDontSee('Journal test fee on LN-LDR-008');
});

it('shows the instalment schedule and an empty state when there is none', function (): void {
    $loan = ldrLoan('LN-LDR-009');
    $empty = ldrLoan('LN-LDR-010');

    AmortizationSchedule::create([
        'loan_id'               => $loan->id,
        'installment_number'    => 1,
        'due_date'              => CarbonImmutable::now()->addDays(30)->toDateString(),
        'principal_due'         => '1125.00',
        'interest_due'          => '16.88',
        'fees_due'              => '56.25',
        'total_installment_due' => '1198.13',
        'principal_paid'        => '0.00',
        'interest_paid'         => '0.00',
        'fees_paid'             => '0.00',
        'status'                => 'pending',
    ]);

    $viewer = ldrUser(UserRole::SupportAgent);

    $this->actingAs($viewer)->get(route('lender.loans.show', [$loan, 'tab' => 'schedule']))
        ->assertOk()->assertSee('KES 1,198.13');

    $this->actingAs($viewer)->get(route('lender.loans.show', [$empty, 'tab' => 'schedule']))
        ->assertOk()->assertSee('No schedule yet');
});

it('returns 404 for a loan that does not exist', function (): void {
    $this->actingAs(ldrUser(UserRole::LenderAdmin))
        ->get(route('lender.loans.show', 999999))
        ->assertNotFound();
});