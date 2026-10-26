<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Models\AuditTrail;
use App\Models\Borrower;
use App\Models\FeeMatrix;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanLegalContract;
use App\Models\LoanProduct;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(RolesSeeder::class);
    Storage::fake('local');
});

function ctdUser(string $n, string $role = 'borrower'): User
{
    $user = User::create([
        'name'         => 'Ctd User ' . $n,
        'email'        => 'ctd' . $n . '@ctd.test',
        'password'     => 'Demo@12345',
        'phone_number' => '254766' . str_pad($n, 6, '0', STR_PAD_LEFT),
        'is_active'    => true,
    ]);
    $user->assignRole($role);

    return $user;
}

function ctdBorrower(string $n, ?User $user): Borrower
{
    return Borrower::create([
        'user_id'        => $user?->id,
        'full_name'      => 'Ctd Borrower ' . $n,
        'id_number'      => '5163' . str_pad($n, 4, '0', STR_PAD_LEFT),
        'phone_number'   => '254777' . str_pad($n, 6, '0', STR_PAD_LEFT),
        'credit_limit'   => '30000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);
}

function ctdLoan(Borrower $borrower, string $number, LoanStatus $status, string $principal = '5000.00'): Loan
{
    $product = LoanProduct::firstOrCreate(['product_code' => 'CTD-30'], [
        'name'                        => 'Ctd Product',
        'min_principal'               => '1000.00',
        'max_principal'               => '50000.00',
        'default_tenure_days'         => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active'                   => true,
    ]);

    $application = LoanApplication::create([
        'application_number'    => 'APP-' . $number,
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => $product->id,
        'requested_principal'   => $principal,
        'requested_tenure_days' => 30,
        'status'                => 'approved',
    ]);

    return Loan::create([
        'loan_number'          => $number,
        'loan_application_id'  => $application->id,
        'borrower_id'          => $borrower->id,
        'loan_product_id'      => $product->id,
        'principal_disbursed'  => $principal,
        'annual_interest_rate' => '18.00',
        'tenure_days'          => 30,
        'status'               => $status,
        'disbursed_at'         => CarbonImmutable::now()->subDays(2),
        'due_date'             => CarbonImmutable::now()->addDays(28),
        'is_in_duplum_locked'  => false,
    ]);
}

it('lets a borrower download their own contract with safe headers, audited, and reuses it', function (): void {
    $user = ctdUser('1');
    $borrower = ctdBorrower('1', $user);
    $loan = ctdLoan($borrower, 'LN-CTD-001', LoanStatus::DISBURSED);
    $url = route('borrower.contract', ['loan' => $loan->id]);

    $this->actingAs($user)
        ->get($url)
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertSee('LN-CTD-001');

    $code = LoanLegalContract::query()->firstOrFail()->qr_verification_code;

    $this->actingAs($user)->get($url)->assertOk();

    expect(LoanLegalContract::query()->count())->toBe(1);
    expect(LoanLegalContract::query()->firstOrFail()->qr_verification_code)->toBe($code);
    expect(AuditTrail::query()->where('action', 'LEGAL_CONTRACT_GENERATED')->count())->toBe(1);
    expect(AuditTrail::query()->where('action', 'LEGAL_CONTRACT_DOWNLOADED')->count())->toBe(2);
});

it('returns 404 for another borrower\'s loan', function (): void {
    $user = ctdUser('2');
    ctdBorrower('2', $user);
    $other = ctdLoan(ctdBorrower('3', null), 'LN-CTD-OTHER', LoanStatus::DISBURSED);

    $this->actingAs($user)
        ->get(route('borrower.contract', ['loan' => $other->id]))
        ->assertNotFound();

    expect(LoanLegalContract::query()->count())->toBe(0);
});

it('has no contract before the money is sent', function (): void {
    $user = ctdUser('4');
    $loan = ctdLoan(ctdBorrower('4', $user), 'LN-CTD-HOLD', LoanStatus::APPROVAL_HOLD);

    $this->actingAs($user)
        ->get(route('borrower.contract', ['loan' => $loan->id]))
        ->assertNotFound();

    expect(LoanLegalContract::query()->count())->toBe(0);
});

it('keeps guests and staff out', function (): void {
    $loan = ctdLoan(ctdBorrower('5', null), 'LN-CTD-GATE', LoanStatus::DISBURSED);
    $url = route('borrower.contract', ['loan' => $loan->id]);

    $this->get($url)->assertRedirect('/login');
    $this->actingAs(ctdUser('50', 'finance_officer'))->get($url)->assertForbidden();
});

it('prints the same fee the ledger uses, with excise included and honest totals', function (): void {
    $user = ctdUser('6');
    $loan = ctdLoan(ctdBorrower('6', $user), 'LN-CTD-FEE', LoanStatus::DISBURSED, '20000.00');

    FeeMatrix::forceCreate([
        'loan_product_id'            => $loan->loan_product_id,
        'fee_name'                   => 'Processing fee',
        'calculation_type'           => 'percentage',
        'fee_amount'                 => '0.00',
        'fee_percentage'             => '5.00',
        'is_excise_duty_applicable'  => true,
    ]);

    // Interest 20,000 x 18% x 30/365 = 295.89. Fee 5% = 1,000.00, of which excise 200.00.
    $this->actingAs($user)
        ->get(route('borrower.contract', ['loan' => $loan->id]))
        ->assertOk()
        ->assertSee('295.89')
        ->assertSee('1,000.00')
        ->assertSee('200.00')
        ->assertSee('21,295.89');
});

it('shows the download link only for loans past payout', function (): void {
    $user = ctdUser('7');
    $borrower = ctdBorrower('7', $user);
    ctdLoan($borrower, 'LN-CTD-A', LoanStatus::DISBURSED);
    ctdLoan($borrower, 'LN-CTD-B', LoanStatus::APPROVAL_HOLD);

    $html = $this->actingAs($user)->get('/borrower/account')->assertOk()->getContent();

    expect(substr_count((string) $html, 'Download contract'))->toBe(1);
});