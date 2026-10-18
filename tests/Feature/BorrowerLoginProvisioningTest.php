<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Requests\BorrowerRegistrationRequest;
use App\Models\Borrower;
use App\Models\User;
use App\Services\Customer\BorrowerOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function blpPayload(array $overrides = []): array
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
        'data_protection_consent'      => true,
    ], $overrides);
}

/**
 * @param array<string, mixed> $data
 * @return list<string>
 */
function blpErrorKeys(array $data): array
{
    $validator = Validator::make($data, (new BorrowerRegistrationRequest())->rules());
    $validator->fails();

    return $validator->errors()->keys();
}

it('creates the login, borrower role and link when a password is supplied', function () {
    $borrower = app(BorrowerOnboardingService::class)
        ->register(blpPayload(), '192.168.1.10', 'PestTestAgent/1.0');

    $user = $borrower->user;

    expect($user)->not->toBeNull()
        ->and($borrower->user_id)->toBe($user->id)
        ->and($user->hasRole(UserRole::Borrower->value))->toBeTrue()
        ->and($user->phone_number)->toBe('254712345678')
        ->and($user->email)->toBe('wanjiku@example.com')
        ->and($user->is_active)->toBeTrue()
        ->and(Hash::check('Salama2026', $user->password))->toBeTrue();

    $auditValues = (string) DB::table('audit_trails')
        ->where('action', 'BORROWER_ONBOARDED')
        ->value('new_values');

    expect($auditValues)->not->toContain('Salama2026')
        ->and($auditValues)->toContain('login_created');
});

it('lets the newly registered borrower reach the portal', function () {
    $this->withoutVite();

    $borrower = app(BorrowerOnboardingService::class)->register(blpPayload());

    $this->actingAs($borrower->user)
        ->get(route('borrower.dashboard'))
        ->assertOk()
        ->assertSee('Wanjiku');
});

it('still registers a borrower without a login when no password is given', function () {
    $data = array_diff_key(blpPayload(), array_flip(['password', 'password_confirmation']));

    $borrower = app(BorrowerOnboardingService::class)->register($data);

    expect($borrower->user_id)->toBeNull()
        ->and(User::count())->toBe(0);
});

it('refuses a phone number that already has a login and saves nothing', function () {
    User::create([
        'name'         => 'Existing Person',
        'email'        => 'existing@ethical-lending.test',
        'password'     => 'Demo@12345',
        'phone_number' => '254712345678',
        'is_active'    => true,
    ]);

    expect(fn () => app(BorrowerOnboardingService::class)->register(blpPayload()))
        ->toThrow(ValidationException::class);

    expect(Borrower::count())->toBe(0);
});

it('refuses an email that already has a login and saves nothing', function () {
    User::create([
        'name'         => 'Existing Person',
        'email'        => 'wanjiku@example.com',
        'password'     => 'Demo@12345',
        'phone_number' => '254700000999',
        'is_active'    => true,
    ]);

    expect(fn () => app(BorrowerOnboardingService::class)->register(blpPayload()))
        ->toThrow(ValidationException::class);

    expect(Borrower::count())->toBe(0);
});

it('uses a placeholder login email when the borrower gave none', function () {
    $borrower = app(BorrowerOnboardingService::class)->register(blpPayload(['email' => null]));

    expect($borrower->user->email)->toBe('254712345678@borrowers.ethical-lending.invalid')
        ->and($borrower->email)->toBeNull();
});

it('rejects a weak password in the registration rules', function () {
    expect(blpErrorKeys(blpPayload(['password' => 'short', 'password_confirmation' => 'short'])))
        ->toContain('password');
});

it('rejects a password that does not match its confirmation', function () {
    expect(blpErrorKeys(blpPayload(['password_confirmation' => 'Different2026'])))
        ->toContain('password');
});

it('accepts a complete valid registration in the rules', function () {
    expect(blpErrorKeys(blpPayload()))->toBe([]);
});