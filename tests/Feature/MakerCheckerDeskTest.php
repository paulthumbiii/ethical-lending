<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditTrail;
use App\Models\MakerCheckerRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function mcdUser(UserRole $role, string $phone): User
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

function mcdRequest(User $maker, string $action = 'WAIVE_INTEREST'): MakerCheckerRequest
{
    return MakerCheckerRequest::create([
        'maker_user_id'      => $maker->id,
        'action_type'        => $action,
        'target_entity_type' => 'App\\Models\\Loan',
        'target_entity_id'   => 1,
        'payload'            => ['reason' => 'Hardship case', 'amount' => '1500.00'],
        'status'             => 'pending',
    ]);
}

it('sends guests to the login page', function () {
    $this->get(route('lender.maker-checker.index'))->assertRedirect(route('login'));
});

it('keeps borrowers out of the review desk', function () {
    $borrower = mcdUser(UserRole::Borrower, '254700000101');

    $this->actingAs($borrower)->get(route('lender.maker-checker.index'))->assertForbidden();
});

it('keeps staff who cannot approve out of the review desk', function () {
    $credit = mcdUser(UserRole::CreditOfficer, '254700000102');

    $this->actingAs($credit)->get(route('lender.maker-checker.index'))->assertForbidden();
});

it('lets approver roles open the review desk', function (string $role) {
    $this->withoutVite();
    $approver = mcdUser(UserRole::from($role), '254700000103');

    $this->actingAs($approver)->get(route('lender.maker-checker.index'))->assertOk();
})->with(['lender_admin', 'finance_officer']);

it('shows pending requests and marks the viewer\'s own request', function () {
    $this->withoutVite();
    $maker = mcdUser(UserRole::CreditOfficer, '254700000104');
    $checker = mcdUser(UserRole::FinanceOfficer, '254700000105');
    mcdRequest($maker);
    mcdRequest($checker, 'WRITE_OFF_LOAN');

    $this->actingAs($checker)
        ->get(route('lender.maker-checker.index'))
        ->assertOk()
        ->assertSee('Waive Interest')
        ->assertSee('Hardship case')
        ->assertSee('Raised by you');
});

it('stops a maker approving their own request', function () {
    $maker = mcdUser(UserRole::FinanceOfficer, '254700000106');
    $request = mcdRequest($maker);

    $this->actingAs($maker)
        ->post(route('lender.maker-checker.approve', $request))
        ->assertSessionHasErrors('review');

    expect($request->fresh()->status)->toBe('pending');
});

it('lets a different approver approve and records the audit trail', function () {
    $maker = mcdUser(UserRole::CreditOfficer, '254700000107');
    $checker = mcdUser(UserRole::FinanceOfficer, '254700000108');
    $request = mcdRequest($maker);

    $this->actingAs($checker)
        ->post(route('lender.maker-checker.approve', $request), ['notes' => 'Checked against the file'])
        ->assertRedirect()
        ->assertSessionHas('status');

    $fresh = $request->fresh();

    expect($fresh->status)->toBe('approved')
        ->and($fresh->checker_user_id)->toBe($checker->id)
        ->and($fresh->checker_notes)->toBe('Checked against the file')
        ->and(AuditTrail::where('action', 'MAKER_CHECKER_AUTHORIZED')->count())->toBe(1);
});

it('blocks staff who are not approvers from deciding', function () {
    $maker = mcdUser(UserRole::FinanceOfficer, '254700000109');
    $collections = mcdUser(UserRole::CollectionsOfficer, '254700000110');
    $request = mcdRequest($maker);

    $this->actingAs($collections)
        ->post(route('lender.maker-checker.approve', $request))
        ->assertForbidden();

    expect($request->fresh()->status)->toBe('pending');
});

it('requires a written reason to reject', function () {
    $maker = mcdUser(UserRole::CreditOfficer, '254700000111');
    $checker = mcdUser(UserRole::LenderAdmin, '254700000112');
    $request = mcdRequest($maker);

    $this->actingAs($checker)
        ->post(route('lender.maker-checker.reject', $request), ['notes' => ''])
        ->assertSessionHasErrors('notes');

    expect($request->fresh()->status)->toBe('pending');
});

it('rejects a request when a reason is given', function () {
    $maker = mcdUser(UserRole::CreditOfficer, '254700000113');
    $checker = mcdUser(UserRole::LenderAdmin, '254700000114');
    $request = mcdRequest($maker);

    $this->actingAs($checker)
        ->post(route('lender.maker-checker.reject', $request), ['notes' => 'Amount does not match the hardship letter'])
        ->assertRedirect()
        ->assertSessionHas('status');

    $fresh = $request->fresh();

    expect($fresh->status)->toBe('rejected')
        ->and($fresh->checker_user_id)->toBe($checker->id)
        ->and($fresh->checker_notes)->toBe('Amount does not match the hardship letter');
});

it('does not let a decided request be decided again', function () {
    $maker = mcdUser(UserRole::CreditOfficer, '254700000115');
    $checker = mcdUser(UserRole::FinanceOfficer, '254700000116');
    $request = mcdRequest($maker);
    $request->update(['status' => 'rejected']);

    $this->actingAs($checker)
        ->post(route('lender.maker-checker.approve', $request))
        ->assertSessionHasErrors('review');

    expect($request->fresh()->status)->toBe('rejected');
});