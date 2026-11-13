<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Enums\UserRole;
use App\Models\AuditTrail;
use App\Models\Borrower;
use App\Models\ChartOfAccount;
use App\Models\CollectionCase;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\LoanWriteOff;
use App\Models\MakerCheckerRequest;
use App\Models\User;
use App\Services\Financial\LoanLedgerService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(RolesSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00', 'Africa/Nairobi'));

    // Ensure standard GL accounts exist for testing
    ChartOfAccount::firstOrCreate(['account_code' => '1200'], ['account_name' => 'Loans Receivable', 'account_type' => 'ASSET', 'currency' => 'KES', 'is_active' => true]);
    ChartOfAccount::firstOrCreate(['account_code' => '5010'], ['account_name' => 'Bad Debt Expense', 'account_type' => 'EXPENSE', 'currency' => 'KES', 'is_active' => true]);
});

function lwoUser(UserRole $role, string $phone): User
{
    Role::findOrCreate($role->value, 'web');

    $user = User::create([
        'name'         => 'Test ' . $role->value,
        'email'        => $role->value . '.' . $phone . '@ethical-lending.test',
        'password'     => bcrypt('Demo@12345'),
        'phone_number' => $phone,
        'is_active'    => true,
    ]);
    $user->assignRole($role->value);

    return $user;
}

function lwoLoan(LoanStatus $status = LoanStatus::DEFAULTED): Loan
{
    static $i = 0;
    $i++;

    $borrower = Borrower::create([
        'full_name'      => 'Borrower ' . $i,
        'id_number'      => '5000000' . $i,
        'phone_number'   => '25471188800' . $i,
        'credit_limit'   => '15000.00',
        'risk_band'      => RiskBand::C,
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::firstOrCreate(
        ['product_code' => 'LWO-30'],
        [
            'name'                        => 'Write-Off Test Product',
            'min_principal'               => '1000.00',
            'max_principal'               => '50000.00',
            'default_tenure_days'         => 30,
            'interest_calculation_method' => 'reducing_balance',
            'is_active'                   => true,
        ]
    );

    $application = LoanApplication::create([
        'application_number'    => 'APP-LWO-' . $i,
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => $product->id,
        'requested_principal'   => '10000.00',
        'requested_tenure_days' => 30,
        'status'                => 'approved',
    ]);

    $loan = Loan::create([
        'loan_number'          => 'LN-LWO-' . $i,
        'loan_application_id'  => $application->id,
        'borrower_id'          => $borrower->id,
        'loan_product_id'      => $product->id,
        'principal_disbursed'  => '10000.00',
        'annual_interest_rate' => '18.00',
        'tenure_days'          => 30,
        'status'               => $status,
        'disbursed_at'         => CarbonImmutable::now()->subDays(120),
        'due_date'             => CarbonImmutable::now()->subDays(90),
        'defaulted_at'         => CarbonImmutable::now()->subDays(30),
        'is_in_duplum_locked'  => true,
    ]);

    // Give the loan a balance
    app(LoanLedgerService::class)->recordEntry($loan->id, 'DISBURSEMENT_PRINCIPAL', '10000.00', '0.00', 'INIT-DISB', 'Disbursed');
    app(LoanLedgerService::class)->recordEntry($loan->id, 'INTEREST_ACCRUAL', '500.00', '0.00', 'INIT-INT', 'Interest');
    app(LoanLedgerService::class)->recordEntry($loan->id, 'PENALTY_CHARGED', '500.00', '0.00', 'INIT-PEN', 'Penalty');

    return $loan;
}

it('submits a write-off request for a defaulted loan and creates a pending maker-checker request', function () {
    $maker = lwoUser(UserRole::CollectionsOfficer, '254700000301');
    $loan = lwoLoan();

    $this->actingAs($maker)
        ->post(route('lender.loans.write-off', $loan), ['reason' => 'Borrower untraceable after 120 DPD'])
        ->assertRedirect()
        ->assertSessionHas('status');

    $request = MakerCheckerRequest::where('target_entity_id', $loan->id)
        ->where('action_type', 'WRITE_OFF_LOAN')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->status)->toBe('pending')
        ->and($request->maker_user_id)->toBe($maker->id)
        ->and($request->payload['total'])->toBe('11000.00')
        ->and($request->payload['reason'])->toBe('Borrower untraceable after 120 DPD')
        ->and(AuditTrail::where('action', 'LOAN_WRITE_OFF_REQUESTED')->count())->toBe(1);
});

it('rejects proposing write-off for an active disbursed loan that is not defaulted', function () {
    $maker = lwoUser(UserRole::CollectionsOfficer, '254700000302');
    $loan = lwoLoan(LoanStatus::DISBURSED);

    $this->actingAs($maker)
        ->post(route('lender.loans.write-off', $loan), ['reason' => 'Early write-off attempt'])
        ->assertSessionHasErrors('write_off');

    expect(MakerCheckerRequest::count())->toBe(0);
});

it('blocks duplicate write-off requests while one is already pending', function () {
    $maker = lwoUser(UserRole::CollectionsOfficer, '254700000303');
    $loan = lwoLoan();

    $this->actingAs($maker)
        ->post(route('lender.loans.write-off', $loan), ['reason' => 'First attempt'])
        ->assertSessionHas('status');

    $this->actingAs($maker)
        ->post(route('lender.loans.write-off', $loan), ['reason' => 'Second attempt'])
        ->assertSessionHasErrors('write_off');

    expect(MakerCheckerRequest::where('target_entity_id', $loan->id)->count())->toBe(1);
});

it('prevents a maker from approving their own write-off request', function () {
    $maker = lwoUser(UserRole::FinanceOfficer, '254700000304');
    $loan = lwoLoan();

    $this->actingAs($maker)
        ->post(route('lender.loans.write-off', $loan), ['reason' => 'Self approval write-off'])
        ->assertSessionHas('status');

    $request = MakerCheckerRequest::where('target_entity_id', $loan->id)->first();

    $this->actingAs($maker)
        ->post(route('lender.maker-checker.approve', $request), ['notes' => 'Attempting self-signoff'])
        ->assertSessionHasErrors('review');

    expect($request->fresh()->status)->toBe('pending')
        ->and($loan->fresh()->status)->toBe(LoanStatus::DEFAULTED);
});

it('executes write-off on checker approval, zeroing balances and flipping status to WRITTEN_OFF', function () {
    $maker = lwoUser(UserRole::CollectionsOfficer, '254700000305');
    $checker = lwoUser(UserRole::FinanceOfficer, '254700000306');
    $loan = lwoLoan();

    $this->actingAs($maker)
        ->post(route('lender.loans.write-off', $loan), ['reason' => 'Unrecoverable after in-duplum freeze'])
        ->assertSessionHas('status');

    $request = MakerCheckerRequest::where('target_entity_id', $loan->id)->first();

    $this->actingAs($checker)
        ->post(route('lender.maker-checker.approve', $request), ['notes' => 'Reviewed and approved for bad debt write-off'])
        ->assertSessionHas('status');

    $freshLoan = $loan->fresh();
    expect($freshLoan->status)->toBe(LoanStatus::WRITTEN_OFF);

    // Assert LoanWriteOff record created with exact numbers
    $writeOff = LoanWriteOff::where('loan_id', $loan->id)->first();
    expect($writeOff)->not->toBeNull()
        ->and((string) $writeOff->written_off_principal)->toBe('10000.00')
        ->and((string) $writeOff->written_off_interest)->toBe('500.00')
        ->and((string) $writeOff->written_off_fees)->toBe('500.00')
        ->and((string) $writeOff->total_written_off)->toBe('11000.00')
        ->and($writeOff->approved_by_user_id)->toBe($checker->id);

    // Assert latest ledger balance is 0.00
    $latestLedger = $freshLoan->ledgerEntries()->latest('id')->first();
    expect((string) $latestLedger->total_running_balance)->toBe('0.00')
        ->and((string) $latestLedger->running_principal_balance)->toBe('0.00')
        ->and((string) $latestLedger->running_interest_balance)->toBe('0.00')
        ->and((string) $latestLedger->running_fees_balance)->toBe('0.00');

    // Assert AuditTrail records
    expect(AuditTrail::where('action', 'LOAN_WRITTEN_OFF')->where('auditable_id', $loan->id)->count())->toBe(1);
});

it('closes any open collection case when write-off is executed', function () {
    $maker = lwoUser(UserRole::CollectionsOfficer, '254700000307');
    $checker = lwoUser(UserRole::LenderAdmin, '254700000308');
    $loan = lwoLoan();

    $case = CollectionCase::create([
        'loan_id'           => $loan->id,
        'borrower_id'       => $loan->borrower_id,
        'dpd_bucket'        => 'DPD_90_PLUS',
        'case_status'       => 'open',
        'last_contacted_at' => null,
    ]);

    $this->actingAs($maker)
        ->post(route('lender.loans.write-off', $loan), ['reason' => 'Write off unrecoverable debt'])
        ->assertSessionHas('status');

    $request = MakerCheckerRequest::where('target_entity_id', $loan->id)->first();

    $this->actingAs($checker)
        ->post(route('lender.maker-checker.approve', $request), ['notes' => 'Confirmed'])
        ->assertSessionHas('status');

    expect($case->fresh()->case_status)->toBe('closed')
        ->and(AuditTrail::where('action', 'COLLECTION_CASE_CLOSED')->where('auditable_id', $case->id)->count())->toBe(1);
});

it('leaves loan in defaulted status when checker rejects write-off request', function () {
    $maker = lwoUser(UserRole::CollectionsOfficer, '254700000309');
    $checker = lwoUser(UserRole::FinanceOfficer, '254700000310');
    $loan = lwoLoan();

    $this->actingAs($maker)
        ->post(route('lender.loans.write-off', $loan), ['reason' => 'Premature write-off request'])
        ->assertSessionHas('status');

    $request = MakerCheckerRequest::where('target_entity_id', $loan->id)->first();

    $this->actingAs($checker)
        ->post(route('lender.maker-checker.reject', $request), ['notes' => 'Recovery attempts still underway with borrower guarantor'])
        ->assertSessionHas('status');

    expect($request->fresh()->status)->toBe('rejected')
        ->and($loan->fresh()->status)->toBe(LoanStatus::DEFAULTED)
        ->and(LoanWriteOff::where('loan_id', $loan->id)->count())->toBe(0);
});