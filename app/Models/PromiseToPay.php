<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PromiseToPay extends Model
{
    protected $table = 'promise_to_pays';

    protected $fillable = [
        'collection_case_id',
        'promised_date',
        'promised_amount',
        'status',
        'honored_at',
    ];

    protected function casts(): array
    {
        return [
            'promised_amount' => 'decimal:2',
            'promised_date'   => 'date',
            'honored_at'      => 'datetime',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(CollectionCase::class, 'collection_case_id');
    }
}