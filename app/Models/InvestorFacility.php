<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class InvestorFacility extends Model
{
    protected $fillable = [
        'investor_name',
        'facility_code',
        'total_facility_amount',
        'drawn_amount',
        'annual_cost_of_funds',
        'start_date',
        'maturity_date',
    ];

    protected function casts(): array
    {
        return [
            'total_facility_amount' => 'decimal:2',
            'drawn_amount'          => 'decimal:2',
            'annual_cost_of_funds'  => 'decimal:2',
            'start_date'            => 'date',
            'maturity_date'         => 'date',
        ];
    }
}