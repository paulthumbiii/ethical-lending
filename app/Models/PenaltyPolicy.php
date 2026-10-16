<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PenaltyPolicy extends Model
{
    protected $fillable = [
        'loan_product_id',
        'grace_period_days',
        'penalty_rate_daily',
        'fixed_late_fee',
    ];

    protected function casts(): array
    {
        return [
            'penalty_rate_daily' => 'decimal:2',
            'fixed_late_fee'     => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }
}