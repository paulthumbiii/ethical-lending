<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RegulatoryCooldownLock extends Model
{
    protected $fillable = [
        'borrower_id',
        'lock_type',
        'locked_until',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'locked_until' => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }
}