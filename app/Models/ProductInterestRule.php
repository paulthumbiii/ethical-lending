<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductInterestRule extends Model
{
    protected $fillable = [
        'loan_product_id',
        'tier_name',
        'annual_rate',
        'monthly_rate',
        'compounding_frequency',
    ];

    protected function casts(): array
    {
        return [
            'annual_rate'  => 'decimal:2',
            'monthly_rate' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }
}