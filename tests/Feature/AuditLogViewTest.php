<?php

declare(strict_types=1);

use App\Models\AuditTrail;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(RolesSeeder::class);
});

function alvUser(string $role): User
{
    static $n = 0;
    $n++;

    $user = User::create([
        'name'         => 'Alv ' . $role . $n,
        'email'        => 'alv' . $n . '@alv.test',
        'password'     => 'Demo@12345',
        'phone_number' => '2547' . sprintf('%08d', 8000000 + $n),
        'is_active'    => true,
    ]);
    $user->assignRole($role);

    return $user;
}

function alvRow(string $action, ?int $userId, string $type = 'App\\Models\\Loan', int $id = 7): void
{
    AuditTrail::create([
        'user_id'        => $userId,
        'action'         => $action,
        'auditable_type' => $type,
        'auditable_id'   => $id,
        'old_values'     => ['secret_old' => 'OLDVALUE123'],
        'new_values'     => ['secret_new' => 'NEWVALUE456'],
        'ip_address'     => '10.1.2.3',
        'user_agent'     => 'UA-SECRET-AGENT',
    ]);
}

it('lets an auditor open the audit log', function (): void {
    $this->actingAs(alvUser('auditor'))->get('/lender/audit')->assertOk();
});

it('lets a compliance officer open the audit log', function (): void {
    $this->actingAs(alvUser('compliance_officer'))->get('/lender/audit')->assertOk();
});

it('blocks finance and support from the audit log', function (): void {
    $this->actingAs(alvUser('finance_officer'))->get('/lender/audit')->assertForbidden();
    $this->actingAs(alvUser('support_agent'))->get('/lender/audit')->assertForbidden();
});

it('sends a guest to login', function (): void {
    $this->get('/lender/audit')->assertRedirect('/login');
});

it('shows who, action, entity and IP but never old values, new values or user agent', function (): void {
    $actor = alvUser('lender_admin');
    alvRow('LOAN_FEE_CHARGED', $actor->id);

    $this->actingAs(alvUser('auditor'))
        ->get('/lender/audit')
        ->assertOk()
        ->assertSee($actor->email)
        ->assertSee('Loan Fee Charged')
        ->assertSee('Loan #7')
        ->assertSee('10.1.2.3')
        ->assertDontSee('OLDVALUE123')
        ->assertDontSee('NEWVALUE456')
        ->assertDontSee('UA-SECRET-AGENT');
});

it('labels rows with no user', function (): void {
    alvRow('LOGIN_FAILED', null, 'auth_attempt', 0);

    $this->actingAs(alvUser('auditor'))
        ->get('/lender/audit')
        ->assertOk()
        ->assertSee('Unknown / not signed in');
});

it('filters by action', function (): void {
    alvRow('LOAN_FEE_CHARGED', null);
    alvRow('STATEMENT_ACCEPTED', null, 'App\\Models\\MpesaStatementUpload', 99);

    $this->actingAs(alvUser('auditor'))
        ->get('/lender/audit?action=STATEMENT_ACCEPTED')
        ->assertOk()
        ->assertSee('MpesaStatementUpload #99')
        ->assertDontSee('Loan #7');
});

it('filters by user name or email', function (): void {
    $a = alvUser('lender_admin');
    $b = alvUser('credit_officer');
    alvRow('LOAN_CREATED', $a->id, 'App\\Models\\Loan', 11);
    alvRow('LOAN_CREATED', $b->id, 'App\\Models\\Loan', 22);

    $this->actingAs(alvUser('auditor'))
        ->get('/lender/audit?user=' . urlencode($a->email))
        ->assertOk()
        ->assertSee('Loan #11')
        ->assertDontSee('Loan #22');
});

it('filters by date', function (): void {
    alvRow('LOAN_CREATED', null, 'App\\Models\\Loan', 33);
    $tomorrow = now()->addDay()->toDateString();

    $this->actingAs(alvUser('auditor'))
        ->get('/lender/audit?from=' . $tomorrow)
        ->assertOk()
        ->assertDontSee('Loan #33')
        ->assertSee('No audit entries');
});

it('paginates at 25 per page', function (): void {
    for ($i = 1; $i <= 30; $i++) {
        alvRow('LOAN_CREATED', null, 'App\\Models\\Loan', 1000 + $i);
    }

    $this->actingAs(alvUser('auditor'))
        ->get('/lender/audit')
        ->assertOk()
        ->assertSee('Loan #1030')
        ->assertDontSee('Loan #1001');
});