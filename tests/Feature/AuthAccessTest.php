<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(RolesSeeder::class);
});

function authTestUser(?UserRole $role, array $overrides = []): User
{
    static $n = 0;
    $n++;

    $user = User::create(array_merge([
        'name' => 'Test User ' . $n,
        'email' => "user{$n}@example.test",
        'password' => 'secret-pass-1',
        'phone_number' => '2547000' . str_pad((string) $n, 5, '0', STR_PAD_LEFT),
        'is_active' => true,
    ], $overrides));

    if ($role !== null) {
        $user->assignRole($role->value);
    }

    return $user;
}

it('shows the login page to guests', function (): void {
    $this->get('/login')->assertOk()->assertSee('Sign in');
});

it('signs staff in with email and sends them to the lender dashboard', function (): void {
    $user = authTestUser(UserRole::CreditOfficer);

    $this->post('/login', ['login' => $user->email, 'password' => 'secret-pass-1'])
        ->assertRedirect(route('lender.dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('signs borrowers in with a local-format phone number', function (): void {
    $user = authTestUser(UserRole::Borrower, ['phone_number' => '254700012345']);

    $this->post('/login', ['login' => '0700 012 345', 'password' => 'secret-pass-1'])
        ->assertRedirect(route('borrower.dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('rejects a wrong password', function (): void {
    $user = authTestUser(UserRole::LenderAdmin);

    $this->post('/login', ['login' => $user->email, 'password' => 'wrong'])
        ->assertSessionHasErrors('login');

    $this->assertGuest();
});

it('blocks inactive accounts', function (): void {
    $user = authTestUser(UserRole::LenderAdmin, ['is_active' => false]);

    $this->post('/login', ['login' => $user->email, 'password' => 'secret-pass-1'])
        ->assertSessionHasErrors('login');

    $this->assertGuest();
});

it('blocks accounts that have no role', function (): void {
    $user = authTestUser(null);

    $this->post('/login', ['login' => $user->email, 'password' => 'secret-pass-1'])
        ->assertSessionHasErrors('login');

    $this->assertGuest();
});

it('sends guests to the login page', function (): void {
    $this->get(route('lender.dashboard'))->assertRedirect(route('login'));
    $this->get(route('borrower.dashboard'))->assertRedirect(route('login'));
});

it('lets every staff role open the lender dashboard', function (): void {
    foreach (UserRole::staff() as $role) {
        $user = authTestUser(UserRole::from($role));

        $this->actingAs($user)->get(route('lender.dashboard'))->assertOk();
    }
});

it('keeps borrowers out of the lender dashboard', function (): void {
    $this->actingAs(authTestUser(UserRole::Borrower))
        ->get(route('lender.dashboard'))
        ->assertForbidden();
});

it('keeps staff out of the borrower portal', function (): void {
    $this->actingAs(authTestUser(UserRole::LenderAdmin))
        ->get(route('borrower.dashboard'))
        ->assertForbidden();
});

it('shows a setup message when a borrower login has no linked profile', function (): void {
    $this->actingAs(authTestUser(UserRole::Borrower))
        ->get(route('borrower.dashboard'))
        ->assertOk()
        ->assertSee('not linked');
});

it('signs users out', function (): void {
    $this->actingAs(authTestUser(UserRole::LenderAdmin))
        ->post('/logout')
        ->assertRedirect(route('login'));

    $this->assertGuest();
});