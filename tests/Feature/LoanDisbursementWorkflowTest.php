<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Enums\UserRole;
use App\Models\Borrower;
use App\Models\ChartOfAccount;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanLedgerEntry;
use App\Models\LoanProduct;
use App\Models\MakerCheckerRequest;
use App\Models\MpesaB2cDisbursement;
use App\Models\ProductInterestRule;
use App\Models\User;
use App\Services\Lending\LoanDisbursementWorkflowService;
use App\Services\Lending\LoanOriginationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function ldwUser(UserRole $role, string $n): User
{
    Role::findOrCreate($role->value, 'web');

    $user = User::create([
        'name'         => 'Ldw ' . $n,
        'email'        => 'ldw-' . $n . '@ethical-lending.test',
        'password'     => 'Demo@12345',
        'phone_number' => '2547001000' . $n,
        'is_active'    => true,
    ]);
    $user->assignRole($role->value);

    return $user;
}

/**
 * @return array{application: LoanApplication, credit: User, finance: User, admin: User, checker: User}
 */
function ldwSetup(string $status = 'approved'): array
{
    ChartOfAccount::create(['account_code' => '1010', 'account_name' => 'M-Pesa Settlement Account', 'account_type' => 'ASSET', 'currency' => 'KES', 'is_active' => true]);
    ChartOfAccount::create(['account_code' => '1200', 'account_name' => 'Loans Receivable', 'account_type' => 'ASSET', 'currency' => 'KES', 'is_active' => true]);

    $credit = ldwUser(UserRole::CreditOfficer, '01');
    $finance = ldwUser(UserRole::FinanceOfficer, '02');
    $admin = ldwUser(UserRole::LenderAdmin, '03');
    // The person who gave the final approval. Never one of the senders above.
    $checker = ldwUser(UserRole::LenderAdmin, '04');

    $borrower = Borrower::create([
        'full_name'      => 'Ldw Borrower',
        'id_number'      => '41222333',
        'phone_number'   => '254712000222',
        'credit_limit'   => '15000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::create([
        'name'                        => 'Ldw Product',
        'product_code'                => 'LDW-30',
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

    $application = LoanApplication::create([
        'application_number'    => 'APP-LDW-01',
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => $product->id,
        'requested_principal'   => '5000.00',
        'requested_tenure_days' => 30,
        'status'                => $status,
    ]);

    if ($status === 'approved') {
        MakerCheckerRequest::create([
            'maker_user_id'      => $credit->id,
            'action_type'        => 'APPROVE_LOAN_APPLICATION',
            'target_entity_type' => LoanApplication::class,
            'target_entity_id'   => $application->id,
            'payload'            => ['application_number' => 'APP-LDW-01'],
            'status'             => 'approved',
            'checker_user_id'    => $checker->id,
            'checker_notes'      => null,
            'actioned_at'        => now(),
        ]);
    }

    return ['application' => $application, 'credit' => $credit, 'finance' => $finance, 'admin' => $admin, 'checker' => $checker];
}

function ldwFakeDaraja(): void
{
    Http::fake([
        '*/oauth/v1/generate*'           => Http::response(['access_token' => 'TEST_TOKEN', 'expires_in' => 3599], 200),
        '*/mpesa/b2c/v1/paymentrequest*' => Http::response([
            'ConversationID'      => 'AG_LDW_001',
            'ResponseCode'        => '0',
            'ResponseDescription' => 'Accept the service request successfully.',
        ], 200),
    ]);
}

function ldwUrl(string $route, LoanApplication $application): string
{
    return route($route, ['loanApplication' => $application->id]);
}

function ldwLoan(LoanApplication $application): ?Loan
{
    return Loan::query()->where('loan_application_id', $application->id)->first();
}

it('lets a finance officer create the loan and start the payout', function (): void {
    $this->withoutVite();
    ldwFakeDaraja();
    $s = ldwSetup();

    $this->actingAs($s['finance'])
        ->post(ldwUrl('lender.applications.disburse', $s['application']))
        ->assertRedirect(ldwUrl('lender.applications.show', $s['application']))
        ->assertSessionHas('status');

    $loan = ldwLoan($s['application']);

    expect($loan)->not->toBeNull()
        ->and($loan->status)->toBe(LoanStatus::APPROVAL_HOLD)
        ->and(MpesaB2cDisbursement::query()->where('loan_id', $loan->id)->where('status', 'initiated')->count())->toBe(1);
});

it('does not let a credit officer send money', function (): void {
    ldwFakeDaraja();
    $s = ldwSetup();

    $this->actingAs($s['credit'])
        ->post(ldwUrl('lender.applications.disburse', $s['application']))
        ->assertForbidden();

    expect(ldwLoan($s['application']))->toBeNull();
});

it('refuses an application that is not approved', function (): void {
    ldwFakeDaraja();
    $s = ldwSetup('pending');

    $this->actingAs($s['finance'])
        ->post(ldwUrl('lender.applications.disburse', $s['application']))
        ->assertSessionHasErrors('review');

    expect(ldwLoan($s['application']))->toBeNull();
});

it('sends nothing the second time the button is pressed', function (): void {
    ldwFakeDaraja();
    $s = ldwSetup();
    $url = ldwUrl('lender.applications.disburse', $s['application']);

    $this->actingAs($s['finance'])->post($url);
    $this->actingAs($s['finance'])->post($url)->assertSessionHasErrors('review');

    $loan = ldwLoan($s['application']);
    expect(MpesaB2cDisbursement::query()->where('loan_id', $loan->id)->count())->toBe(1);
});

it('resumes with the same loan after a failed attempt', function (): void {
    ldwFakeDaraja();
    $s = ldwSetup();
    $loan = app(LoanOriginationService::class)->originate($s['application']->id, $s['finance']->id, '127.0.0.1', 'Pest');

    MpesaB2cDisbursement::create([
        'loan_id'         => $loan->id,
        'recipient_phone' => '254712000222',
        'amount'          => (string) $loan->principal_disbursed,
        'status'          => 'failed',
    ]);

    $this->actingAs($s['finance'])
        ->post(ldwUrl('lender.applications.disburse', $s['application']))
        ->assertSessionHas('status');

    expect(Loan::query()->where('loan_application_id', $s['application']->id)->count())->toBe(1)
        ->and(MpesaB2cDisbursement::query()->where('loan_id', $loan->id)->where('status', 'initiated')->count())->toBe(1);
});

it('shows a plain error when Safaricom rejects the request', function (): void {
    Http::fake([
        '*/oauth/v1/generate*'           => Http::response(['access_token' => 'TEST_TOKEN', 'expires_in' => 3599], 200),
        '*/mpesa/b2c/v1/paymentrequest*' => Http::response([
            'ResponseCode'        => '400',
            'ResponseDescription' => 'Bad request',
        ], 400),
    ]);
    $s = ldwSetup();

    $this->actingAs($s['finance'])
        ->post(ldwUrl('lender.applications.disburse', $s['application']))
        ->assertSessionHasErrors('review');

    $loan = ldwLoan($s['application']);
    expect($loan->status)->toBe(LoanStatus::APPROVAL_HOLD)
        ->and(MpesaB2cDisbursement::query()->where('loan_id', $loan->id)->where('status', 'failed')->count())->toBe(1);
});

it('completes the payout with a simulated result after a real request was started', function (): void {
    ldwFakeDaraja();
    $s = ldwSetup();

    $this->actingAs($s['finance'])->post(ldwUrl('lender.applications.disburse', $s['application']));
    $this->actingAs($s['finance'])
        ->post(ldwUrl('lender.applications.simulate-payout', $s['application']))
        ->assertSessionHas('status');

    $loan = ldwLoan($s['application']);
    expect($loan->status)->toBe(LoanStatus::DISBURSED)
        ->and($loan->disbursed_at)->not->toBeNull()
        ->and(MpesaB2cDisbursement::query()->where('loan_id', $loan->id)->count())->toBe(1)
        ->and(MpesaB2cDisbursement::query()->where('loan_id', $loan->id)->first()->status)->toBe('successful')
        ->and(LoanLedgerEntry::query()->where('loan_id', $loan->id)->count())->toBe(1);
});

it('can simulate a payout for a loan that has no request yet', function (): void {
    $s = ldwSetup();
    $loan = app(LoanOriginationService::class)->originate($s['application']->id, $s['admin']->id, '127.0.0.1', 'Pest');

    $this->actingAs($s['admin'])
        ->post(ldwUrl('lender.applications.simulate-payout', $s['application']))
        ->assertSessionHas('status');

    $loan->refresh();
    expect($loan->status)->toBe(LoanStatus::DISBURSED)
        ->and(LoanLedgerEntry::query()->where('loan_id', $loan->id)->count())->toBe(1);
});

it('refuses a simulated result in production', function (): void {
    $s = ldwSetup();
    $loan = app(LoanOriginationService::class)->originate($s['application']->id, $s['admin']->id, '127.0.0.1', 'Pest');

    $this->app->detectEnvironment(fn () => 'production');

    try {
        expect(fn () => app(LoanDisbursementWorkflowService::class)
            ->simulateResult($s['application']->id, $s['admin']->id, '127.0.0.1', 'Pest'))
            ->toThrow(RuntimeException::class, 'Simulated results are only available on a local test setup.');
    } finally {
        $this->app->detectEnvironment(fn () => 'testing');
    }

    $loan->refresh();
    expect($loan->status)->toBe(LoanStatus::APPROVAL_HOLD)
        ->and(LoanLedgerEntry::query()->where('loan_id', $loan->id)->count())->toBe(0);
});

it('does not let a credit officer simulate a payout', function (): void {
    $s = ldwSetup();
    app(LoanOriginationService::class)->originate($s['application']->id, $s['admin']->id, '127.0.0.1', 'Pest');

    $this->actingAs($s['credit'])
        ->post(ldwUrl('lender.applications.simulate-payout', $s['application']))
        ->assertForbidden();
});

it('shows the send button to a finance officer', function (): void {
    $this->withoutVite();
    $s = ldwSetup();

    $this->actingAs($s['finance'])
        ->get(ldwUrl('lender.applications.show', $s['application']))
        ->assertOk()
        ->assertSee('Create loan and send money');
});

it('tells a credit officer that only finance and admins can send money', function (): void {
    $this->withoutVite();
    $s = ldwSetup();

    $this->actingAs($s['credit'])
        ->get(ldwUrl('lender.applications.show', $s['application']))
        ->assertOk()
        ->assertSee('Only finance officers and lender admins can send money.')
        ->assertDontSee('Create loan and send money');
});

it('shows that the money was sent once the payout is complete', function (): void {
    $this->withoutVite();
    $s = ldwSetup();
    app(LoanOriginationService::class)->originate($s['application']->id, $s['admin']->id, '127.0.0.1', 'Pest');

    $this->actingAs($s['admin'])->post(ldwUrl('lender.applications.simulate-payout', $s['application']));

    $this->actingAs($s['admin'])
        ->get(ldwUrl('lender.applications.show', $s['application']))
        ->assertOk()
        ->assertSee('Money sent to the borrower.');
});

it('stops the final approver from sending the money', function (): void {
    ldwFakeDaraja();
    $s = ldwSetup();
    MakerCheckerRequest::query()->update(['checker_user_id' => $s['admin']->id]);

    $this->actingAs($s['admin'])
        ->post(ldwUrl('lender.applications.disburse', $s['application']))
        ->assertSessionHasErrors('review');

    expect(ldwLoan($s['application']))->toBeNull();
});

it('stops the final approver from simulating the payout', function (): void {
    $s = ldwSetup();
    $loan = app(LoanOriginationService::class)->originate($s['application']->id, $s['finance']->id, '127.0.0.1', 'Pest');
    MakerCheckerRequest::query()->update(['checker_user_id' => $s['admin']->id]);

    $this->actingAs($s['admin'])
        ->post(ldwUrl('lender.applications.simulate-payout', $s['application']))
        ->assertSessionHasErrors('review');

    expect($loan->fresh()->status)->toBe(LoanStatus::APPROVAL_HOLD);
});

it('replaces the send button with an explanation for the final approver', function (): void {
    $this->withoutVite();
    $s = ldwSetup();
    MakerCheckerRequest::query()->update(['checker_user_id' => $s['admin']->id]);

    $this->actingAs($s['admin'])
        ->get(ldwUrl('lender.applications.show', $s['application']))
        ->assertOk()
        ->assertSee('You gave the final approval')
        ->assertDontSee('Create loan and send money');
});