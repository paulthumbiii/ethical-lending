<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CrbDisputeLock extends Model
{
    use HasFactory;

    protected $fillable = [
        'borrower_id',
        'loan_id',
        'bureau_name',
        'dispute_reference',
        'dispute_reason',
        'is_active',
        'locked_at',
        'lock_expires_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'locked_at' => 'datetime',
            'lock_expires_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}