<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Borrower;
use App\Models\User;
use Illuminate\Database\Seeder;

/** LOCAL DEMO ONLY. Never run this against a live database. */
final class DemoUsersSeeder extends Seeder
{
    private const PASSWORD = 'Demo@12345';

    public function run(): void
    {
        $this->call(RolesSeeder::class);

        $staff = [
            ['Grace Njeri', 'admin@ethical-lending.test', '254700000001', UserRole::LenderAdmin],
            ['Peter Otieno', 'credit@ethical-lending.test', '254700000002', UserRole::CreditOfficer],
            ['Mary Achieng', 'collections@ethical-lending.test', '254700000003', UserRole::CollectionsOfficer],
            ['John Kamau', 'finance@ethical-lending.test', '254700000004', UserRole::FinanceOfficer],
        ];

        foreach ($staff as [$name, $email, $phone, $role]) {
            $user = User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => self::PASSWORD,
                'phone_number' => $phone,
                'is_active' => true,
            ]);
            $user->syncRoles([$role->value]);
        }

        $borrowerUser = User::updateOrCreate(['email' => 'borrower@ethical-lending.test'], [
            'name' => 'Amina Wanjiku',
            'password' => self::PASSWORD,
            'phone_number' => '254700000010',
            'is_active' => true,
        ]);
        $borrowerUser->syncRoles([UserRole::Borrower->value]);

        Borrower::updateOrCreate(['id_number' => '12345678'], [
            'user_id' => $borrowerUser->id,
            'full_name' => 'Amina Wanjiku',
            'kra_pin' => 'A123456789B',
            'phone_number' => '254700000010',
            'email' => 'borrower@ethical-lending.test',
            'credit_limit' => '15000.00',
            'risk_band' => 'B',
            'is_blacklisted' => false,
        ]);
    }
}