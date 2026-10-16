<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BorrowerFinancialProfile extends Model
{
    protected $fillable = [
        'borrower_id',
        'monthly_income',
        'monthly_expenses',
        'mpesa_average_monthly_inflow',
        'existing_debt_obligations',
    ];

    protected function casts(): array
    {
        return [
            'monthly_income'               => 'decimal:2',
            'monthly_expenses'              => 'decimal:2',
            'mpesa_average_monthly_inflow' => 'decimal:2',
            'existing_debt_obligations'    => 'decimal:2',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }
}