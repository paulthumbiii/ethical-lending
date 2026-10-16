<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WebhookIdempotencyKey extends Model
{
    protected $fillable = [
        'idempotency_key',
        'source_provider',
        'status',
        'response_payload',
        'locked_until',
    ];

    protected function casts(): array
    {
        return [
            'response_payload' => 'array',
            'locked_until'     => 'datetime',
        ];
    }
}