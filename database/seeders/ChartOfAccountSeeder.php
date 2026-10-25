<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;

final class ChartOfAccountSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['1010', 'M-Pesa Settlement Account', 'ASSET'],
            ['1200', 'Loans Receivable', 'ASSET'],
            ['2010', 'Customer Overpayment Credits', 'LIABILITY'],
            ['2030', 'KRA Excise Duty Payable', 'LIABILITY'],
            ['4010', 'Interest Income', 'REVENUE'],
            ['4020', 'Fee Income', 'REVENUE'],
        ];

        foreach ($accounts as [$code, $name, $type]) {
            ChartOfAccount::firstOrCreate(
                ['account_code' => $code],
                [
                    'account_name' => $name,
                    'account_type' => $type,
                    'currency'     => 'KES',
                    'is_active'    => true,
                ]
            );
        }
    }
}