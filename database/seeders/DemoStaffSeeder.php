<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Demo logins for the three Phase 12 roles. Local/testing only.
 */
final class DemoStaffSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoStaffSeeder must never run in production.');
        }

        // Idempotent: makes sure roles and permissions exist before assigning them.
        $this->call(RolesSeeder::class);

        $people = [
            ['Wanjiru Kariuki', 'compliance@ethical-lending.test', '254700000005', 'compliance_officer'],
            ['David Mutua', 'auditor@ethical-lending.test', '254700000006', 'auditor'],
            ['Faith Chebet', 'support@ethical-lending.test', '254700000007', 'support_agent'],
        ];

        foreach ($people as [$name, $email, $phone, $role]) {
            $user = User::query()->where('email', $email)->first()
                ?? User::create([
                    'name'         => $name,
                    'email'        => $email,
                    'password'     => 'Demo@12345',
                    'phone_number' => $phone,
                    'is_active'    => true,
                ]);

            $user->syncRoles([$role]);
        }
    }
}