<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\FeeMatrix;
use App\Models\LoanProduct;
use App\Models\ProductInterestRule;
use Illuminate\Database\Seeder;

final class LoanProductSeeder extends Seeder
{
    public function run(): void
    {
        $product = LoanProduct::firstOrCreate(
            ['product_code' => 'ETH-QUICK30'],
            [
                'name' => 'Quick Cash 30',
                'min_principal' => '1000.00',
                'max_principal' => '20000.00',
                'default_tenure_days' => 30,
                'interest_calculation_method' => 'reducing_balance',
                'is_active' => true,
            ]
        );

        ProductInterestRule::firstOrCreate(
            ['loan_product_id' => $product->id, 'tier_name' => 'Standard'],
            ['annual_rate' => '18.00', 'monthly_rate' => '1.50', 'compounding_frequency' => 'none']
        );

        FeeMatrix::firstOrCreate(
            ['loan_product_id' => $product->id, 'fee_name' => 'Processing fee'],
            [
                'calculation_type' => 'percentage',
                'fee_amount' => '0.00',
                'fee_percentage' => '5.00',
                'is_excise_duty_applicable' => true,
            ]
        );
    }
}