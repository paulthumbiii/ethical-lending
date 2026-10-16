<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MakerCheckerRequest extends Model
{
    protected $fillable = [
        'maker_user_id',
        'action_type',
        'target_entity_type',
        'target_entity_id',
        'payload',
        'status',
        'checker_user_id',
        'checker_notes',
        'actioned_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'     => 'array',
            'actioned_at' => 'datetime',
        ];
    }

    public function maker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'maker_user_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checker_user_id');
    }
}