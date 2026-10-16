<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AmortizationSchedule extends Model
{
    protected $fillable = [
        'loan_id',
        'installment_number',
        'due_date',
        'principal_due',
        'interest_due',
        'fees_due',
        'total_installment_due',
        'principal_paid',
        'interest_paid',
        'fees_paid',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'principal_due'         => 'decimal:2',
            'interest_due'          => 'decimal:2',
            'fees_due'              => 'decimal:2',
            'total_installment_due' => 'decimal:2',
            'principal_paid'        => 'decimal:2',
            'interest_paid'         => 'decimal:2',
            'fees_paid'             => 'decimal:2',
            'due_date'              => 'date',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }
}