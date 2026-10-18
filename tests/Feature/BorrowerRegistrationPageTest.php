<?php

declare(strict_types=1);

use App\Enums\RiskBand;
use App\Models\Borrower;
use App\Models\DataProtectionConsent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function brpPayload(array $overrides = []): array
{
    return array_merge([
        'full_name'                    => 'Wanjiku Kamau',
        'id_number'                    => '32891042',
        'phone_number'                 => '0712345678',
        'kra_pin'                      => 'A019283746X',
        'email'                        => 'wanjiku@example.com',
        'password'                     => 'Salama2026',
        'password_confirmation'        => 'Salama2026',
        'monthly_income'               => '85000.00',
        'monthly_expenses'             => '35000.00',
        'mpesa_average_monthly_inflow' => '90000.00',
        'existing_debt_obligations'    => '5000.00',
        'data_protection_consent'      => '1',
    ], $overrides);
}

it('shows the sign-up page to guests', function () {
    $this->withoutVite();

    $this->get('/register')
        ->assertOk()
        ->assertSee('Create your account')
        ->assertSee('Kenya Data Protection Act, 2019');
});

it('links to sign-up from the login page', function () {
    $this->withoutVite();

    $this->get('/login')->assertOk()->assertSee('Create an account');
});

it('registers a borrower, signs them in and shows their portal', function () {
    $this->withoutVite();

    $this->post('/register', brpPayload())
        ->assertRedirect(route('borrower.dashboard'));

    $borrower = Borrower::query()->where('id_number', '32891042')->firstOrFail();

    expect($borrower->user_id)->not->toBeNull()
        ->and($borrower->phone_number)->toBe('254712345678');

    $this->assertAuthenticatedAs($borrower->user);

    expect(DataProtectionConsent::query()->where('borrower_id', $borrower->id)->count())->toBe(1);

    $this->get(route('borrower.dashboard'))
        ->assertOk()
        ->assertSee('Hello, Wanjiku')
        ->assertSee('You have no active loan right now.');
});

it('lets a new borrower sign out and sign back in with a local-format phone number', function () {
    $this->withoutVite();

    $this->post('/register', brpPayload())->assertRedirect(route('borrower.dashboard'));
    $this->post('/logout')->assertRedirect(route('login'));
    $this->assertGuest();

    $this->post('/login', ['login' => '0712 345 678', 'password' => 'Salama2026'])
        ->assertRedirect(route('borrower.dashboard'));

    $this->assertAuthenticated();
});

it('will not register without data protection consent', function () {
    $payload = brpPayload();
    unset($payload['data_protection_consent']);

    $this->post('/register', $payload)->assertSessionHasErrors('data_protection_consent');

    expect(Borrower::count())->toBe(0)
        ->and(User::count())->toBe(0);

    $this->assertGuest();
});

it('rejects passwords that do not match', function () {
    $this->post('/register', brpPayload(['password_confirmation' => 'Different2026']))
        ->assertSessionHasErrors('password');

    expect(Borrower::count())->toBe(0);
    $this->assertGuest();
});

it('refuses a phone number that already has a login and saves nothing', function () {
    User::create([
        'name'         => 'Existing Person',
        'email'        => 'existing@ethical-lending.test',
        'password'     => 'Demo@12345',
        'phone_number' => '254712345678',
        'is_active'    => true,
    ]);

    $this->post('/register', brpPayload())->assertSessionHasErrors('phone_number');

    expect(Borrower::count())->toBe(0);
    $this->assertGuest();
});

it('refuses an ID number that is already registered', function () {
    Borrower::create([
        'full_name'      => 'Someone Else',
        'id_number'      => '32891042',
        'phone_number'   => '254700000555',
        'credit_limit'   => '0.00',
        'risk_band'      => RiskBand::C,
        'is_blacklisted' => false,
    ]);

    $this->post('/register', brpPayload())->assertSessionHasErrors('id_number');

    expect(User::count())->toBe(0);
    $this->assertGuest();
});

it('sends signed-in users away from the sign-up page', function () {
    $user = User::create([
        'name'         => 'Already In',
        'email'        => 'already@ethical-lending.test',
        'password'     => 'Demo@12345',
        'phone_number' => '254700000556',
        'is_active'    => true,
    ]);

    $this->actingAs($user)->get('/register')->assertRedirect();
});

it('throttles repeated sign-up attempts', function () {
    $server = ['REMOTE_ADDR' => '10.9.9.9'];

    for ($i = 0; $i < 5; $i++) {
        $this->withServerVariables($server)->post('/register', [])->assertStatus(302);
    }

    $this->withServerVariables($server)->post('/register', [])->assertStatus(429);
});