<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FeeMatrix extends Model
{
    protected $table = 'fee_matrices';

    protected $fillable = [
        'loan_product_id',
        'fee_name',
        'calculation_type',
        'fee_amount',
        'fee_percentage',
        'is_excise_duty_applicable',
    ];

    protected function casts(): array
    {
        return [
            'fee_amount'                 => 'decimal:2',
            'fee_percentage'             => 'decimal:2',
            'is_excise_duty_applicable' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }
}