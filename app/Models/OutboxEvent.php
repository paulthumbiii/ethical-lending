<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OutboxEvent extends Model
{
    protected $fillable = [
        'aggregate_type',
        'aggregate_id',
        'event_name',
        'payload',
        'previous_event_hash',
        'current_event_hash',
        'is_published',
        'published_at',
        'retry_count',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload'      => 'array',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }
}