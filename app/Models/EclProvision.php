<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EclProvision extends Model
{
    protected $fillable = [
        'loan_id',
        'reporting_date',
        'exposure_at_default',
        'probability_of_default',
        'loss_given_default',
        'calculated_ecl',
    ];

    protected function casts(): array
    {
        return [
            'exposure_at_default'    => 'decimal:2',
            'probability_of_default' => 'decimal:4',
            'loss_given_default'     => 'decimal:4',
            'calculated_ecl'         => 'decimal:2',
            'reporting_date'         => 'date',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }
}