<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DemoStaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('creates the three demo staff with the right roles and permissions', function (): void {
    $this->seed(DemoStaffSeeder::class);

    $compliance = User::where('email', 'compliance@ethical-lending.test')->first();
    $auditor    = User::where('email', 'auditor@ethical-lending.test')->first();
    $support    = User::where('email', 'support@ethical-lending.test')->first();

    expect($compliance->hasRole('compliance_officer'))->toBeTrue()
        ->and($auditor->hasRole('auditor'))->toBeTrue()
        ->and($support->hasRole('support_agent'))->toBeTrue()
        ->and($auditor->can('audit.view'))->toBeTrue()
        ->and($auditor->can('finance.view'))->toBeTrue()
        ->and($support->can('audit.view'))->toBeFalse()
        ->and($support->can('finance.view'))->toBeFalse()
        ->and($support->can('loans.view'))->toBeTrue();
});

it('is safe to run twice', function (): void {
    $this->seed(DemoStaffSeeder::class);
    $this->seed(DemoStaffSeeder::class);

    expect(User::where('email', 'like', '%@ethical-lending.test')->count())->toBe(3);
});