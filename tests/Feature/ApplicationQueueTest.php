<?php

declare(strict_types=1);

use App\Enums\StatementReviewStatus;
use App\Enums\UserRole;
use App\Models\AuditTrail;
use App\Models\Borrower;
use App\Models\BorrowerFinancialProfile;
use App\Models\CreditRuleEvaluation;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\MpesaStatementUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function aquUser(UserRole $role): User
{
    static $n = 0;
    $n++;

    Role::findOrCreate($role->value, 'web');

    $user = User::create([
        'name' => 'Aqu ' . $role->value . ' ' . $n,
        'email' => "aqu{$n}@example.test",
        'password' => 'Secret@12345',
        'phone_number' => '25471' . str_pad((string) $n, 7, '0', STR_PAD_LEFT),
        'is_active' => true,
    ]);

    $user->assignRole($role->value);

    return $user;
}

function aquApplication(string $inflow = '0.00', string $requested = '5000.00', string $status = 'pending', bool $withStatement = true): LoanApplication
{
    static $n = 0;
    $n++;

    $borrower = Borrower::create([
        'full_name' => 'Aqu Borrower ' . $n,
        'id_number' => '5' . str_pad((string) $n, 7, '0', STR_PAD_LEFT),
        'phone_number' => '25472' . str_pad((string) $n, 7, '0', STR_PAD_LEFT),
        'credit_limit' => '0.00',
        'risk_band' => 'C',
        'is_blacklisted' => false,
    ]);

    BorrowerFinancialProfile::create([
        'borrower_id' => $borrower->id,
        'monthly_income' => '60000.00',
        'monthly_expenses' => '25000.00',
        'mpesa_average_monthly_inflow' => $inflow,
        'existing_debt_obligations' => '0.00',
    ]);

    $product = LoanProduct::firstOrCreate(
        ['product_code' => 'AQU-TEST'],
        [
            'name' => 'Aqu Test Loan',
            'min_principal' => '1000.00',
            'max_principal' => '20000.00',
            'default_tenure_days' => 30,
            'interest_calculation_method' => 'reducing_balance',
            'is_active' => true,
        ]
    );

    $application = LoanApplication::create([
        'application_number' => 'APP-AQU-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT),
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'requested_principal' => $requested,
        'requested_tenure_days' => 30,
        'status' => $status,
    ]);

    if ($withStatement) {
        MpesaStatementUpload::create([
            'borrower_id' => $borrower->id,
            'loan_application_id' => $application->id,
            'file_path' => 'statements/aqu-' . $n . '.pdf',
            'review_status' => StatementReviewStatus::Accepted->value,
            'valid_until' => now()->addDays(30)->toDateString(),
        ]);
    }

    return $application;
}

it('lets a credit officer open the applications queue', function () {
    $this->withoutVite();
    $app = aquApplication();

    $this->actingAs(aquUser(UserRole::CreditOfficer))
        ->get(route('lender.applications.index'))
        ->assertOk()
        ->assertSee($app->application_number);
});

it('lets a finance officer view an application but not run a check', function () {
    $this->withoutVite();
    $app = aquApplication();
    $finance = aquUser(UserRole::FinanceOfficer);

    $this->actingAs($finance)
        ->get(route('lender.applications.show', ['loanApplication' => $app->id]))
        ->assertOk()
        ->assertSee($app->application_number)
        ->assertDontSee('Run credit check');

    $this->actingAs($finance)
        ->post(route('lender.applications.check', ['loanApplication' => $app->id]))
        ->assertForbidden();

    expect(CreditRuleEvaluation::query()->count())->toBe(0);
});

it('keeps collections officers and borrowers out', function () {
    $this->withoutVite();

    $this->actingAs(aquUser(UserRole::CollectionsOfficer))
        ->get(route('lender.applications.index'))
        ->assertForbidden();

    $this->actingAs(aquUser(UserRole::Borrower))
        ->get(route('lender.applications.index'))
        ->assertForbidden();
});

it('sends guests to the login page', function () {
    $this->get(route('lender.applications.index'))
        ->assertRedirect(route('login'));
});

it('declines an application with no verified inflow and records who ran it', function () {
    $app = aquApplication();
    $officer = aquUser(UserRole::CreditOfficer);

    $this->actingAs($officer)
        ->post(route('lender.applications.check', ['loanApplication' => $app->id]))
        ->assertRedirect(route('lender.applications.show', ['loanApplication' => $app->id]));

    $app->refresh();

    expect($app->status)->toBe('rejected');
    expect((int) $app->underwriter_id)->toBe((int) $officer->id);
    expect(CreditRuleEvaluation::query()->where('loan_application_id', $app->id)->count())->toBe(4);
    expect(CreditRuleEvaluation::query()->where('loan_application_id', $app->id)->where('status', 'FAILED')->count())->toBe(1);
    expect(AuditTrail::query()
        ->where('action', 'APPLICATION_CREDIT_CHECK_RUN')
        ->where('auditable_id', $app->id)
        ->count())->toBe(1);
});

it('passes an application once staff enter enough verified inflow', function () {
    $app = aquApplication();
    $officer = aquUser(UserRole::CreditOfficer);

    $this->actingAs($officer)
        ->post(route('lender.applications.inflow', ['loanApplication' => $app->id]), [
            'mpesa_average_monthly_inflow' => '7500',
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($officer)
        ->post(route('lender.applications.check', ['loanApplication' => $app->id]))
        ->assertSessionHasNoErrors();

    $app->refresh();
    $borrower = Borrower::query()->findOrFail($app->borrower_id);

    expect($app->status)->toBe('approved_pending_checker');
    expect(bccomp((string) $borrower->credit_limit, '3750.00', 2))->toBe(0);

    $audit = AuditTrail::query()
        ->where('action', 'APPLICATION_INFLOW_UPDATED')
        ->where('auditable_id', $app->id)
        ->firstOrFail();

    expect($audit->old_values['mpesa_average_monthly_inflow'])->toBe('0.00');
    expect($audit->new_values['mpesa_average_monthly_inflow'])->toBe('7500.00');
    expect((int) $audit->user_id)->toBe((int) $officer->id);
});

it('refuses to change inflow after the check has run', function () {
    $app = aquApplication();
    $officer = aquUser(UserRole::CreditOfficer);

    $this->actingAs($officer)
        ->post(route('lender.applications.check', ['loanApplication' => $app->id]));

    $this->actingAs($officer)
        ->post(route('lender.applications.inflow', ['loanApplication' => $app->id]), [
            'mpesa_average_monthly_inflow' => '9000',
        ])
        ->assertSessionHasErrors('review');

    $profile = BorrowerFinancialProfile::query()->where('borrower_id', $app->borrower_id)->firstOrFail();

    expect(bccomp((string) $profile->mpesa_average_monthly_inflow, '0.00', 2))->toBe(0);
});

it('will not run the same check twice', function () {
    $app = aquApplication();
    $officer = aquUser(UserRole::LenderAdmin);

    $this->actingAs($officer)
        ->post(route('lender.applications.check', ['loanApplication' => $app->id]));

    $this->actingAs($officer)
        ->post(route('lender.applications.check', ['loanApplication' => $app->id]))
        ->assertSessionHasErrors('review');

    expect(CreditRuleEvaluation::query()->where('loan_application_id', $app->id)->count())->toBe(4);
    expect(AuditTrail::query()
        ->where('action', 'APPLICATION_CREDIT_CHECK_RUN')
        ->where('auditable_id', $app->id)
        ->count())->toBe(1);
});

it('rejects an invalid inflow amount', function () {
    $app = aquApplication();
    $officer = aquUser(UserRole::CreditOfficer);

    foreach (['abc', '-500', '7,500', '10.123'] as $bad) {
        $this->actingAs($officer)
            ->post(route('lender.applications.inflow', ['loanApplication' => $app->id]), [
                'mpesa_average_monthly_inflow' => $bad,
            ])
            ->assertSessionHasErrors('mpesa_average_monthly_inflow');
    }

    expect(AuditTrail::query()->where('action', 'APPLICATION_INFLOW_UPDATED')->count())->toBe(0);
});