<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LoanProduct extends Model
{
    protected $fillable = [
        'name',
        'product_code',
        'min_principal',
        'max_principal',
        'default_tenure_days',
        'interest_calculation_method',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_principal' => 'decimal:2',
            'max_principal' => 'decimal:2',
            'is_active'     => 'boolean',
        ];
    }

    public function interestRules(): HasMany
    {
        return $this->hasMany(ProductInterestRule::class, 'loan_product_id');
    }

    public function feeMatrices(): HasMany
    {
        return $this->hasMany(FeeMatrix::class, 'loan_product_id');
    }

    public function penaltyPolicies(): HasMany
    {
        return $this->hasMany(PenaltyPolicy::class, 'loan_product_id');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class, 'loan_product_id');
    }
}