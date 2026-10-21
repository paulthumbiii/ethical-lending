<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditTrail;
use App\Models\Borrower;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\MakerCheckerRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function fapUser(UserRole $role, string $phone): User
{
    Role::findOrCreate($role->value, 'web');

    $user = User::create([
        'name' => 'FAP ' . $role->value,
        'email' => $role->value . '.' . $phone . '@ethical-lending.test',
        'password' => 'Demo@12345',
        'phone_number' => $phone,
        'is_active' => true,
    ]);

    $user->assignRole($role->value);

    return $user;
}

function fapApplication(string $status = 'approved_pending_checker'): LoanApplication
{
    $borrower = Borrower::create([
        'full_name' => 'Final Approval Tester',
        'id_number' => '32000001',
        'phone_number' => '254733000001',
        'credit_limit' => '3750.00',
        'risk_band' => 'C',
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::create([
        'name' => 'Final Approval Product',
        'product_code' => 'FAP-30',
        'min_principal' => '1000.00',
        'max_principal' => '20000.00',
        'default_tenure_days' => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active' => true,
    ]);

    return LoanApplication::create([
        'application_number' => 'APP-FAP-' . strtoupper(Str::random(6)),
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'requested_principal' => '5000.00',
        'requested_tenure_days' => 30,
        'status' => $status,
    ]);
}

function fapSendUrl(LoanApplication $application): string
{
    return route('lender.applications.final-approval', ['loanApplication' => $application->id]);
}

function fapShowUrl(LoanApplication $application): string
{
    return route('lender.applications.show', ['loanApplication' => $application->id]);
}

it('lets a credit officer send a passed application for final approval', function () {
    $credit = fapUser(UserRole::CreditOfficer, '254722000001');
    $application = fapApplication();

    $this->actingAs($credit)
        ->post(fapSendUrl($application))
        ->assertRedirect(fapShowUrl($application))
        ->assertSessionHas('status');

    $request = MakerCheckerRequest::first();

    expect(MakerCheckerRequest::count())->toBe(1)
        ->and($request->action_type)->toBe('APPROVE_LOAN_APPLICATION')
        ->and($request->target_entity_type)->toBe(LoanApplication::class)
        ->and((int) $request->target_entity_id)->toBe((int) $application->id)
        ->and((int) $request->maker_user_id)->toBe((int) $credit->id)
        ->and($request->status)->toBe('pending')
        ->and($request->payload['application_number'])->toBe($application->application_number)
        ->and($request->payload['amount_requested'])->toBe('5000.00')
        ->and(AuditTrail::where('action', 'APPLICATION_SENT_FOR_APPROVAL')->count())->toBe(1)
        ->and($application->fresh()->status)->toBe('approved_pending_checker');
});

it('keeps finance officers from sending an application for approval', function () {
    $finance = fapUser(UserRole::FinanceOfficer, '254722000002');
    $application = fapApplication();

    $this->actingAs($finance)->post(fapSendUrl($application))->assertForbidden();

    expect(MakerCheckerRequest::count())->toBe(0);
});

it('refuses to send an application that has not passed the credit check', function () {
    $credit = fapUser(UserRole::CreditOfficer, '254722000003');
    $application = fapApplication('pending');

    $this->actingAs($credit)
        ->post(fapSendUrl($application))
        ->assertSessionHasErrors('review');

    expect(MakerCheckerRequest::count())->toBe(0);
});

it('will not raise a second request while one is waiting', function () {
    $credit = fapUser(UserRole::CreditOfficer, '254722000004');
    $application = fapApplication();

    $this->actingAs($credit)->post(fapSendUrl($application))->assertSessionHas('status');

    $this->actingAs($credit)
        ->post(fapSendUrl($application))
        ->assertSessionHasErrors('review');

    expect(MakerCheckerRequest::count())->toBe(1);
});

it('approves the application when a different approver approves in the review desk', function () {
    $credit = fapUser(UserRole::CreditOfficer, '254722000005');
    $finance = fapUser(UserRole::FinanceOfficer, '254722000006');
    $application = fapApplication();

    $this->actingAs($credit)->post(fapSendUrl($application));
    $request = MakerCheckerRequest::first();

    $this->actingAs($finance)
        ->post(route('lender.maker-checker.approve', $request), ['notes' => 'Documents match'])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($application->fresh()->status)->toBe('approved')
        ->and($request->fresh()->status)->toBe('approved')
        ->and($request->fresh()->checker_notes)->toBe('Documents match')
        ->and(AuditTrail::where('action', 'APPLICATION_FINAL_APPROVED')->count())->toBe(1);
});

it('stops the person who sent it from approving it themselves', function () {
    $admin = fapUser(UserRole::LenderAdmin, '254722000007');
    $application = fapApplication();

    $this->actingAs($admin)->post(fapSendUrl($application));
    $request = MakerCheckerRequest::first();

    $this->actingAs($admin)
        ->post(route('lender.maker-checker.approve', $request))
        ->assertSessionHasErrors('review');

    expect($application->fresh()->status)->toBe('approved_pending_checker')
        ->and($request->fresh()->status)->toBe('pending');
});

it('closes the application when the second approver rejects, and keeps the staff note internal', function () {
    $credit = fapUser(UserRole::CreditOfficer, '254722000008');
    $finance = fapUser(UserRole::FinanceOfficer, '254722000009');
    $application = fapApplication();

    $this->actingAs($credit)->post(fapSendUrl($application));
    $request = MakerCheckerRequest::first();

    $this->actingAs($finance)
        ->post(route('lender.maker-checker.reject', $request), ['notes' => 'Internal: inflow looks inflated'])
        ->assertRedirect()
        ->assertSessionHas('status');

    $fresh = $application->fresh();

    expect($fresh->status)->toBe('rejected')
        ->and($fresh->rejection_reason)->toBe('This application did not pass final review.')
        ->and($fresh->rejection_reason)->not->toContain('Internal')
        ->and($request->fresh()->status)->toBe('rejected')
        ->and($request->fresh()->checker_notes)->toBe('Internal: inflow looks inflated')
        ->and(AuditTrail::where('action', 'APPLICATION_FINAL_REJECTED')->count())->toBe(1);
});

it('refuses to approve once the application is no longer waiting', function () {
    $credit = fapUser(UserRole::CreditOfficer, '254722000010');
    $finance = fapUser(UserRole::FinanceOfficer, '254722000011');
    $application = fapApplication();

    $this->actingAs($credit)->post(fapSendUrl($application));
    $request = MakerCheckerRequest::first();

    LoanApplication::whereKey($application->id)->update(['status' => 'rejected']);

    $this->actingAs($finance)
        ->post(route('lender.maker-checker.approve', $request))
        ->assertSessionHasErrors('review');

    expect($application->fresh()->status)->toBe('rejected')
        ->and($request->fresh()->status)->toBe('pending');
});

it('shows the send button to a credit officer but not to a finance officer', function () {
    $this->withoutVite();
    $credit = fapUser(UserRole::CreditOfficer, '254722000012');
    $finance = fapUser(UserRole::FinanceOfficer, '254722000013');
    $application = fapApplication();

    $this->actingAs($credit)
        ->get(fapShowUrl($application))
        ->assertOk()
        ->assertSee('Send for final approval');

    $this->actingAs($finance)
        ->get(fapShowUrl($application))
        ->assertOk()
        ->assertDontSee('Send for final approval');
});

it('shows that the application is waiting once it has been sent', function () {
    $this->withoutVite();
    $credit = fapUser(UserRole::CreditOfficer, '254722000014');
    $application = fapApplication();

    $this->actingAs($credit)->post(fapSendUrl($application));

    $this->actingAs($credit)
        ->get(fapShowUrl($application))
        ->assertOk()
        ->assertSee('Waiting for a second approver')
        ->assertDontSee('Send for final approval');
});