<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BorrowerOptOut extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'borrower_id',
        'channel',
        'opt_out_reason',
        'opted_out_at',
    ];

    protected function casts(): array
    {
        return [
            'opted_out_at' => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }
}