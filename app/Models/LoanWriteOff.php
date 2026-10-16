<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LoanWriteOff extends Model
{
    protected $fillable = [
        'loan_id',
        'written_off_principal',
        'written_off_interest',
        'written_off_fees',
        'total_written_off',
        'write_off_reason',
        'approved_by_user_id',
        'written_off_at',
        'post_write_off_recovered_amount',
    ];

    protected function casts(): array
    {
        return [
            'written_off_principal'           => 'decimal:2',
            'written_off_interest'            => 'decimal:2',
            'written_off_fees'                => 'decimal:2',
            'total_written_off'               => 'decimal:2',
            'post_write_off_recovered_amount' => 'decimal:2',
            'written_off_at'                  => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}