<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LoanLedgerEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'loan_id',
        'entry_type',
        'debit',
        'credit',
        'running_principal_balance',
        'running_interest_balance',
        'running_fees_balance',
        'total_running_balance',
        'reference',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'debit'                     => 'decimal:2',
            'credit'                    => 'decimal:2',
            'running_principal_balance' => 'decimal:2',
            'running_interest_balance'  => 'decimal:2',
            'running_fees_balance'      => 'decimal:2',
            'total_running_balance'     => 'decimal:2',
            'created_at'                => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }
}