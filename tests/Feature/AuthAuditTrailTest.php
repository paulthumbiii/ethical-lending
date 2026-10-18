<?php

declare(strict_types=1);

use App\Models\AuditTrail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function laaUser(bool $active = true): User
{
    Role::findOrCreate('lender_admin', 'web');

    $user = User::create([
        'name' => 'Audit Tester',
        'email' => 'laa.user@example.com',
        'password' => 'Salama2026',
        'phone_number' => '254722000999',
        'is_active' => $active,
    ]);

    $user->assignRole('lender_admin');

    return $user;
}

it('writes a LOGIN_SUCCEEDED row when someone signs in', function () {
    $this->withoutVite();
    $user = laaUser();

    $this->post(route('login.store'), [
        'login' => 'laa.user@example.com',
        'password' => 'Salama2026',
    ]);

    $row = AuditTrail::where('action', 'LOGIN_SUCCEEDED')->where('user_id', $user->id)->first();

    expect($row)->not->toBeNull();
    expect($row->auditable_type)->toBe(User::class);
    expect($row->auditable_id)->toBe($user->id);
    expect($row->ip_address)->not->toBe('');
});

it('writes a masked LOGIN_FAILED row for a wrong password and never stores the password', function () {
    $this->withoutVite();
    $user = laaUser();

    $this->post(route('login.store'), [
        'login' => 'laa.user@example.com',
        'password' => 'WrongPass999',
    ]);

    $row = AuditTrail::where('action', 'LOGIN_FAILED')->first();

    expect($row)->not->toBeNull();
    expect($row->user_id)->toBe($user->id);
    expect($row->new_values['identifier'])->toBe('l***@example.com');
    expect(json_encode($row->getAttributes()))->not->toContain('WrongPass999');
});

it('writes a LOGIN_FAILED row with no user when the account does not exist', function () {
    $this->withoutVite();

    $this->post(route('login.store'), [
        'login' => 'ghost@example.com',
        'password' => 'Whatever123',
    ]);

    $row = AuditTrail::where('action', 'LOGIN_FAILED')->first();

    expect($row)->not->toBeNull();
    expect($row->user_id)->toBeNull();
    expect($row->auditable_type)->toBe('auth_attempt');
    expect($row->auditable_id)->toBe(0);
    expect($row->new_values['identifier'])->toBe('g***@example.com');
});

it('masks a typed phone number in failed login rows', function () {
    $this->withoutVite();

    $this->post(route('login.store'), [
        'login' => '0722000111',
        'password' => 'Whatever123',
    ]);

    $row = AuditTrail::where('action', 'LOGIN_FAILED')->first();
    $stored = json_encode($row->new_values);

    expect($row)->not->toBeNull();
    expect($stored)->not->toContain('0722000111');
    expect($stored)->not->toContain('254722000111');
    expect($stored)->toContain('*');
});

it('writes a LOGOUT row when a signed-in user signs out', function () {
    $this->withoutVite();
    $user = laaUser();

    $this->actingAs($user)->post(route('logout'));

    $row = AuditTrail::where('action', 'LOGOUT')->where('user_id', $user->id)->first();

    expect($row)->not->toBeNull();
    expect($row->auditable_id)->toBe($user->id);
});