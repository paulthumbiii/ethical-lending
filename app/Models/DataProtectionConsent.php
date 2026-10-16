<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DataProtectionConsent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'borrower_id',
        'consent_type',
        'is_granted',
        'ip_address',
        'user_agent',
        'granted_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_granted' => 'boolean',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }
}