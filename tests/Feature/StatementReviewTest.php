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
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function srtUser(UserRole $role): User
{
    static $n = 0;
    $n++;

    Role::findOrCreate($role->value, 'web');

    $user = User::create([
        'name' => 'Srt ' . $role->value . ' ' . $n,
        'email' => "srt{$n}@example.test",
        'password' => 'Secret@12345',
        'phone_number' => '25474' . str_pad((string) $n, 7, '0', STR_PAD_LEFT),
        'is_active' => true,
    ]);

    $user->assignRole($role->value);

    return $user;
}

/** @return array{0: LoanApplication, 1: MpesaStatementUpload} */
function srtApplication(array $flags = []): array
{
    static $n = 0;
    $n++;

    $borrower = Borrower::create([
        'full_name' => 'Srt Borrower ' . $n,
        'id_number' => '7' . str_pad((string) $n, 7, '0', STR_PAD_LEFT),
        'phone_number' => '25475' . str_pad((string) $n, 7, '0', STR_PAD_LEFT),
        'credit_limit' => '0.00',
        'risk_band' => 'C',
        'is_blacklisted' => false,
    ]);

    BorrowerFinancialProfile::create([
        'borrower_id' => $borrower->id,
        'monthly_income' => '60000.00',
        'monthly_expenses' => '25000.00',
        'mpesa_average_monthly_inflow' => '0.00',
        'existing_debt_obligations' => '0.00',
    ]);

    $product = LoanProduct::firstOrCreate(
        ['product_code' => 'SRT-TEST'],
        [
            'name' => 'Srt Test Loan',
            'min_principal' => '1000.00',
            'max_principal' => '20000.00',
            'default_tenure_days' => 30,
            'interest_calculation_method' => 'reducing_balance',
            'is_active' => true,
        ]
    );

    $application = LoanApplication::create([
        'application_number' => 'APP-SRT-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT),
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'requested_principal' => '5000.00',
        'requested_tenure_days' => 30,
        'status' => 'pending',
    ]);

    $upload = MpesaStatementUpload::create([
        'borrower_id' => $borrower->id,
        'loan_application_id' => $application->id,
        'file_path' => 'statements/' . $borrower->id . '/srt-' . $n . '.pdf',
        'original_name' => 'my-statement.pdf',
        'file_size' => 2048,
        'file_sha256' => str_repeat('a', 64),
        'pdf_password_encrypted' => Crypt::encryptString('Pdf-Pass-1'),
        'parsing_status' => 'pending',
        'review_status' => StatementReviewStatus::AwaitingReview->value,
        'flags' => $flags,
    ]);

    return [$application, $upload];
}

function srtAcceptUrl(MpesaStatementUpload $upload): string
{
    return route('lender.applications.statements.accept', ['mpesaStatementUpload' => $upload->id]);
}

function srtRejectUrl(MpesaStatementUpload $upload): string
{
    return route('lender.applications.statements.reject', ['mpesaStatementUpload' => $upload->id]);
}

it('shows a credit officer the statement, its flags and the review controls', function () {
    $this->withoutVite();
    [$application] = srtApplication(['same_file_other_borrower']);

    $this->actingAs(srtUser(UserRole::CreditOfficer))
        ->get(route('lender.applications.show', ['loanApplication' => $application->id]))
        ->assertOk()
        ->assertSee('my-statement.pdf')
        ->assertSee('different borrower')
        ->assertSee('Open PDF')
        ->assertSee('Show password')
        ->assertSee('Accept statement')
        ->assertDontSee('Pdf-Pass-1');
});

it('keeps finance officers from opening or deciding on statements', function () {
    $this->withoutVite();
    [$application, $upload] = srtApplication();
    $finance = srtUser(UserRole::FinanceOfficer);

    $this->actingAs($finance)
        ->get(route('lender.applications.show', ['loanApplication' => $application->id]))
        ->assertOk()
        ->assertDontSee('Open PDF')
        ->assertDontSee('Show password')
        ->assertDontSee('Accept statement');

    $this->actingAs($finance)
        ->post(srtAcceptUrl($upload), ['verified_inflow' => '7500'])
        ->assertForbidden();

    expect($upload->fresh()->review_status)->toBe(StatementReviewStatus::AwaitingReview);
});

it('accepts a statement, sets the verified inflow, erases the password and audits it', function () {
    [$application, $upload] = srtApplication();
    $officer = srtUser(UserRole::CreditOfficer);

    $this->actingAs($officer)
        ->post(srtAcceptUrl($upload), ['verified_inflow' => '7500'])
        ->assertSessionHasNoErrors();

    $upload->refresh();

    expect($upload->review_status)->toBe(StatementReviewStatus::Accepted);
    expect((int) $upload->reviewed_by_user_id)->toBe((int) $officer->id);
    expect($upload->pdf_password_encrypted)->toBeNull();
    expect($upload->valid_until->toDateString())->toBe(now()->addDays(30)->toDateString());

    $profile = BorrowerFinancialProfile::query()->where('borrower_id', $application->borrower_id)->firstOrFail();
    expect(bccomp((string) $profile->mpesa_average_monthly_inflow, '7500.00', 2))->toBe(0);

    $audit = AuditTrail::query()
        ->where('action', 'STATEMENT_ACCEPTED')
        ->where('auditable_id', $upload->id)
        ->firstOrFail();

    expect($audit->old_values['mpesa_average_monthly_inflow'])->toBe('0.00');
    expect($audit->new_values['mpesa_average_monthly_inflow'])->toBe('7500.00');
    expect((int) $audit->user_id)->toBe((int) $officer->id);
});

it('demands a written note before accepting a flagged statement', function () {
    [, $upload] = srtApplication(['same_file_other_borrower']);
    $officer = srtUser(UserRole::CreditOfficer);

    $this->actingAs($officer)
        ->post(srtAcceptUrl($upload), ['verified_inflow' => '7500'])
        ->assertSessionHasErrors('review');

    expect($upload->fresh()->review_status)->toBe(StatementReviewStatus::AwaitingReview);

    $this->actingAs($officer)
        ->post(srtAcceptUrl($upload), [
            'verified_inflow' => '7500',
            'notes' => 'Spoke to the borrower; the shared file was a family account statement.',
        ])
        ->assertSessionHasNoErrors();

    expect($upload->fresh()->review_status)->toBe(StatementReviewStatus::Accepted);
});

it('rejects a statement with a reason, erases the password and audits it', function () {
    [, $upload] = srtApplication();
    $officer = srtUser(UserRole::LenderAdmin);

    $this->actingAs($officer)
        ->post(srtRejectUrl($upload), ['reason' => 'The running balance does not add up on page 2.'])
        ->assertSessionHasNoErrors();

    $upload->refresh();

    expect($upload->review_status)->toBe(StatementReviewStatus::Rejected);
    expect($upload->review_notes)->toBe('The running balance does not add up on page 2.');
    expect($upload->pdf_password_encrypted)->toBeNull();
    expect(AuditTrail::query()->where('action', 'STATEMENT_REJECTED')->where('auditable_id', $upload->id)->count())->toBe(1);
});

it('will not reject a statement without a reason', function () {
    [, $upload] = srtApplication();

    $this->actingAs(srtUser(UserRole::CreditOfficer))
        ->post(srtRejectUrl($upload), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($upload->fresh()->review_status)->toBe(StatementReviewStatus::AwaitingReview);
});

it('will not decide on the same statement twice', function () {
    [, $upload] = srtApplication();
    $officer = srtUser(UserRole::CreditOfficer);

    $this->actingAs($officer)->post(srtAcceptUrl($upload), ['verified_inflow' => '7500']);

    $this->actingAs($officer)
        ->post(srtRejectUrl($upload), ['reason' => 'Changed my mind.'])
        ->assertSessionHasErrors('review');

    $this->actingAs($officer)
        ->post(srtAcceptUrl($upload), ['verified_inflow' => '9000'])
        ->assertSessionHasErrors('review');

    expect($upload->fresh()->review_status)->toBe(StatementReviewStatus::Accepted);
    expect(AuditTrail::query()->where('action', 'STATEMENT_ACCEPTED')->count())->toBe(1);
});

it('streams the PDF to a credit officer, records the view and blocks everyone else', function () {
    Storage::fake('local');
    [, $upload] = srtApplication();
    Storage::disk('local')->put($upload->file_path, "%PDF-1.4\n%%EOF");

    $url = route('lender.applications.statements.file', ['mpesaStatementUpload' => $upload->id]);
    $officer = srtUser(UserRole::CreditOfficer);

    $this->actingAs($officer)->get($url)->assertOk();

    expect(AuditTrail::query()
        ->where('action', 'STATEMENT_VIEWED')
        ->where('auditable_id', $upload->id)
        ->where('user_id', $officer->id)
        ->count())->toBe(1);

    $this->actingAs(srtUser(UserRole::FinanceOfficer))->get($url)->assertForbidden();
    $this->actingAs(srtUser(UserRole::Borrower))->get($url)->assertForbidden();
});

it('shows the password once on request and audits the reveal without storing it', function () {
    [, $upload] = srtApplication();
    $officer = srtUser(UserRole::CreditOfficer);

    $this->actingAs($officer)
        ->post(route('lender.applications.statements.password', ['mpesaStatementUpload' => $upload->id]))
        ->assertSessionHas('statement_password', 'Pdf-Pass-1');

    $audit = AuditTrail::query()
        ->where('action', 'STATEMENT_PASSWORD_REVEALED')
        ->where('auditable_id', $upload->id)
        ->firstOrFail();

    expect((int) $audit->user_id)->toBe((int) $officer->id);
    expect(json_encode([$audit->old_values, $audit->new_values]))->not->toContain('Pdf-Pass-1');

    $this->actingAs($officer)->post(srtAcceptUrl($upload), ['verified_inflow' => '7500']);

    $this->actingAs($officer)
        ->post(route('lender.applications.statements.password', ['mpesaStatementUpload' => $upload->id]))
        ->assertSessionHasErrors('review');
});

it('only runs the credit check once a valid statement has been accepted', function () {
    [$application, $upload] = srtApplication();
    $officer = srtUser(UserRole::CreditOfficer);
    $checkUrl = route('lender.applications.check', ['loanApplication' => $application->id]);

    $this->actingAs($officer)->post($checkUrl)->assertSessionHasErrors('review');

    expect(CreditRuleEvaluation::query()->count())->toBe(0);
    expect($application->fresh()->status)->toBe('pending');

    $this->actingAs($officer)->post(srtAcceptUrl($upload), ['verified_inflow' => '7500']);

    $upload->refresh()->update(['valid_until' => now()->subDay()->toDateString()]);

    $this->actingAs($officer)->post($checkUrl)->assertSessionHasErrors('review');
    expect(CreditRuleEvaluation::query()->count())->toBe(0);

    $upload->update(['valid_until' => now()->addDays(10)->toDateString()]);

    $this->actingAs($officer)->post($checkUrl)->assertSessionHasNoErrors();

    expect($application->fresh()->status)->toBe('approved_pending_checker');
});

it('rejects an invalid verified inflow amount', function () {
    [, $upload] = srtApplication();
    $officer = srtUser(UserRole::CreditOfficer);

    foreach (['abc', '-500', '7,500', '10.123', ''] as $bad) {
        $this->actingAs($officer)
            ->post(srtAcceptUrl($upload), ['verified_inflow' => $bad])
            ->assertSessionHasErrors('verified_inflow');
    }

    expect($upload->fresh()->review_status)->toBe(StatementReviewStatus::AwaitingReview);
    expect(AuditTrail::query()->where('action', 'STATEMENT_ACCEPTED')->count())->toBe(0);
});