<?php

declare(strict_types=1);

use App\Enums\StaffPermission;
use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(RolesSeeder::class);
});

function rpmUser(UserRole $role): User
{
    static $n = 0;
    $n++;

    $user = User::create([
        'name' => 'Role Test ' . $n,
        'email' => "rpm{$n}@example.test",
        'password' => 'secret-pass-1',
        'phone_number' => '2547001' . str_pad((string) $n, 5, '0', STR_PAD_LEFT),
        'is_active' => true,
    ]);
    $user->assignRole($role->value);

    return $user;
}

/** @return list<UserRole> */
function rpmNewRoles(): array
{
    return [UserRole::ComplianceOfficer, UserRole::Auditor, UserRole::SupportAgent];
}

it('creates the three new roles and every permission', function (): void {
    foreach (rpmNewRoles() as $role) {
        expect(Role::where('name', $role->value)->where('guard_name', 'web')->exists())->toBeTrue();
    }

    expect(Permission::count())->toBe(count(StaffPermission::cases()));
});

it('lets the new roles sign in and open the lender dashboard', function (): void {
    foreach (rpmNewRoles() as $role) {
        $user = rpmUser($role);

        $this->post('/login', ['login' => $user->email, 'password' => 'secret-pass-1'])
            ->assertRedirect(route('lender.dashboard'));

        $this->get(route('lender.dashboard'))->assertOk();

        $this->post('/logout');
    }
});

it('keeps the new roles out of applications, review desk and collections', function (): void {
    foreach (rpmNewRoles() as $role) {
        $user = rpmUser($role);

        $this->actingAs($user)->get(route('lender.applications.index'))->assertForbidden();
        $this->actingAs($user)->get(route('lender.maker-checker.index'))->assertForbidden();
        $this->actingAs($user)->get(route('lender.collections.index'))->assertForbidden();
    }
});

it('gives the auditor view permissions only and support no documents or ledger', function (): void {
    $auditor = Role::findByName(UserRole::Auditor->value, 'web');

    expect($auditor->permissions->pluck('name')->every(fn (string $name): bool => str_ends_with($name, '.view')))->toBeTrue()
        ->and($auditor->permissions->count())->toBeGreaterThan(0);

    $support = rpmUser(UserRole::SupportAgent);

    expect($support->can(StaffPermission::BorrowersView->value))->toBeTrue()
        ->and($support->can(StaffPermission::StatementsView->value))->toBeFalse()
        ->and($support->can(StaffPermission::LedgerView->value))->toBeFalse();
});

it('can be seeded twice without duplicating permissions', function (): void {
    $this->seed(RolesSeeder::class);

    $admin = Role::findByName(UserRole::LenderAdmin->value, 'web');

    expect(Permission::count())->toBe(count(StaffPermission::cases()))
        ->and($admin->permissions->count())->toBe(count(StaffPermission::cases()));
});