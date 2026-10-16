<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentWaterfallLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'payment_waterfall_logs';

    protected $fillable = [
        'loan_id',
        'payment_reference',
        'total_amount_received',
        'allocated_to_fees',
        'allocated_to_interest',
        'allocated_to_principal',
        'overpayment_amount',
    ];

    protected function casts(): array
    {
        return [
            'total_amount_received' => 'decimal:2',
            'allocated_to_fees' => 'decimal:2',
            'allocated_to_interest' => 'decimal:2',
            'allocated_to_principal' => 'decimal:2',
            'overpayment_amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}