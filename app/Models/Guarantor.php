<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Guarantor extends Model
{
    protected $fillable = [
        'borrower_id',
        'full_name',
        'id_number',
        'phone_number',
        'guaranteed_amount',
        'verification_token',
        'status',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'guaranteed_amount' => 'decimal:2',
            'signed_at'         => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }
}