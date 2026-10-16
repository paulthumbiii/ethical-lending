<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TaxWithholdingLedger extends Model
{
    protected $fillable = [
        'loan_id',
        'fee_name',
        'fee_charged',
        'excise_rate',
        'excise_duty_amount',
        'remittance_status',
        'remitted_at',
    ];

    protected function casts(): array
    {
        return [
            'fee_charged'        => 'decimal:2',
            'excise_rate'        => 'decimal:2',
            'excise_duty_amount' => 'decimal:2',
            'remitted_at'        => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }
}