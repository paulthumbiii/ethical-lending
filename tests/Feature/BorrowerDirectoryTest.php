<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Enums\UserRole;
use App\Models\AuditTrail;
use App\Models\Borrower;
use App\Models\BorrowerFinancialProfile;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\MpesaStatementUpload;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(RolesSeeder::class);
});

function bdrUser(UserRole $role): User
{
    static $n = 0;
    $n++;

    $user = User::create([
        'name'         => 'Dir Test ' . $n,
        'email'        => "bdr{$n}@example.test",
        'password'     => 'secret-pass-1',
        'phone_number' => '2547002' . str_pad((string) $n, 5, '0', STR_PAD_LEFT),
        'is_active'    => true,
    ]);
    $user->assignRole($role->value);

    return $user;
}

function bdrBorrower(string $name, array $overrides = []): Borrower
{
    static $n = 0;
    $n++;

    return Borrower::create(array_merge([
        'full_name'      => $name,
        'id_number'      => '3100' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        'phone_number'   => '2547440' . str_pad((string) $n, 5, '0', STR_PAD_LEFT),
        'credit_limit'   => '1125.00',
        'risk_band'      => RiskBand::C,
        'is_blacklisted' => false,
    ], $overrides));
}

function bdrLoan(Borrower $borrower, string $number): Loan
{
    $product = LoanProduct::firstOrCreate(['product_code' => 'BDR-30'], [
        'name'                        => 'Directory Test Product',
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

    return Loan::create([
        'loan_number'          => $number,
        'loan_application_id'  => $application->id,
        'borrower_id'          => $borrower->id,
        'loan_product_id'      => $product->id,
        'principal_disbursed'  => '1125.00',
        'annual_interest_rate' => '18.00',
        'tenure_days'          => 30,
        'status'               => LoanStatus::DISBURSED,
        'disbursed_at'         => CarbonImmutable::now()->subDays(5),
        'due_date'             => CarbonImmutable::now()->addDays(25),
        'is_in_duplum_locked'  => false,
    ]);
}

it('sends guests to the login page', function (): void {
    $this->get(route('lender.borrowers.index'))->assertRedirect(route('login'));
});

it('keeps borrowers out of the directory and the borrower page', function (): void {
    $borrowerUser = bdrUser(UserRole::Borrower);
    $borrower = bdrBorrower('Amina Wanjiku');

    $this->actingAs($borrowerUser)->get(route('lender.borrowers.index'))->assertForbidden();
    $this->actingAs($borrowerUser)->get(route('lender.borrowers.show', $borrower))->assertForbidden();
});

it('lets every staff role open the borrower list', function (): void {
    bdrBorrower('Amina Wanjiku');

    foreach (UserRole::staff() as $role) {
        $this->actingAs(bdrUser(UserRole::from($role)))
            ->get(route('lender.borrowers.index'))
            ->assertOk()
            ->assertSee('Amina Wanjiku');
    }
});

it('searches by name, phone and ID number and shows an empty state', function (): void {
    $amina = bdrBorrower('Amina Wanjiku');
    bdrBorrower('Brian Otieno');
    $viewer = bdrUser(UserRole::SupportAgent);

    $this->actingAs($viewer)->get(route('lender.borrowers.index', ['q' => 'Amina']))
        ->assertOk()->assertSee('Amina Wanjiku')->assertDontSee('Brian Otieno');

    $this->actingAs($viewer)->get(route('lender.borrowers.index', ['q' => $amina->phone_number]))
        ->assertOk()->assertSee('Amina Wanjiku')->assertDontSee('Brian Otieno');

    $this->actingAs($viewer)->get(route('lender.borrowers.index', ['q' => $amina->id_number]))
        ->assertOk()->assertSee('Amina Wanjiku')->assertDontSee('Brian Otieno');

    $this->actingAs($viewer)->get(route('lender.borrowers.index', ['q' => 'zzzz-nobody']))
        ->assertOk()->assertSee('No borrowers found');
});

it('filters by blacklist status', function (): void {
    bdrBorrower('Clear Person');
    bdrBorrower('Listed Person', ['is_blacklisted' => true]);
    $viewer = bdrUser(UserRole::Auditor);

    $this->actingAs($viewer)->get(route('lender.borrowers.index', ['status' => 'blacklisted']))
        ->assertOk()->assertSee('Listed Person')->assertDontSee('Clear Person');

    $this->actingAs($viewer)->get(route('lender.borrowers.index', ['status' => 'clear']))
        ->assertOk()->assertSee('Clear Person')->assertDontSee('Listed Person');
});

it('pages the list at 25 rows', function (): void {
    foreach (range(1, 30) as $i) {
        bdrBorrower('Pager Person ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT));
    }

    $this->actingAs(bdrUser(UserRole::LenderAdmin))
        ->get(route('lender.borrowers.index'))
        ->assertOk()
        ->assertSee('Showing 1 to 25 of 30')
        ->assertDontSee('Pager Person 30');
});

it('shows the overview with identity, band, limit and financial profile', function (): void {
    $borrower = bdrBorrower('Njeri Mwangi');
    BorrowerFinancialProfile::create([
        'borrower_id'                  => $borrower->id,
        'monthly_income'               => '60000.00',
        'monthly_expenses'             => '20000.00',
        'mpesa_average_monthly_inflow' => '80000.00',
        'existing_debt_obligations'    => '3000.00',
    ]);

    $this->actingAs(bdrUser(UserRole::SupportAgent))
        ->get(route('lender.borrowers.show', $borrower))
        ->assertOk()
        ->assertSee('Njeri Mwangi')
        ->assertSee($borrower->phone_number)
        ->assertSee('KES 1,125.00')
        ->assertSee('KES 80,000.00');
});

it('lists the borrower loans and applications on their tabs', function (): void {
    $borrower = bdrBorrower('Otieno Barasa');
    bdrLoan($borrower, 'LN-BDR-001');
    $viewer = bdrUser(UserRole::Auditor);

    $this->actingAs($viewer)->get(route('lender.borrowers.show', [$borrower, 'tab' => 'loans']))
        ->assertOk()->assertSee('LN-BDR-001')->assertSee('Disbursed');

    $this->actingAs($viewer)->get(route('lender.borrowers.show', [$borrower, 'tab' => 'applications']))
        ->assertOk()->assertSee('APP-LN-BDR-001');
});

it('shows the statements tab only to people who may decide on statements', function (): void {
    $borrower = bdrBorrower('Statement Owner');
    MpesaStatementUpload::create([
        'borrower_id'   => $borrower->id,
        'file_path'     => 'statements/x/test.pdf',
        'original_name' => 'statement-march.pdf',
        'file_size'     => 2048,
        'file_sha256'   => str_repeat('a', 64),
        'parsing_status' => 'pending',
    ]);

    $this->actingAs(bdrUser(UserRole::CreditOfficer))
        ->get(route('lender.borrowers.show', [$borrower, 'tab' => 'statements']))
        ->assertOk()
        ->assertSee('statement-march.pdf');

    $support = bdrUser(UserRole::SupportAgent);

    $this->actingAs($support)
        ->get(route('lender.borrowers.show', [$borrower, 'tab' => 'statements']))
        ->assertOk()
        ->assertDontSee('statement-march.pdf')
        ->assertDontSee('tab=statements');
});

it('shows the activity tab from the audit trail', function (): void {
    $borrower = bdrBorrower('Activity Person');
    AuditTrail::create([
        'user_id'        => null,
        'action'         => 'LOAN_APPLICATION_SUBMITTED',
        'auditable_type' => Borrower::class,
        'auditable_id'   => $borrower->id,
        'ip_address'     => '127.0.0.1',
        'user_agent'     => 'pest',
    ]);

    $this->actingAs(bdrUser(UserRole::Auditor))
        ->get(route('lender.borrowers.show', [$borrower, 'tab' => 'activity']))
        ->assertOk()
        ->assertSee('LOAN_APPLICATION_SUBMITTED');
});

it('returns 404 for a borrower that does not exist', function (): void {
    $this->actingAs(bdrUser(UserRole::LenderAdmin))
        ->get(route('lender.borrowers.show', 999999))
        ->assertNotFound();
});