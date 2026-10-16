<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CollectionAction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'collection_case_id',
        'performed_by_user_id',
        'action_type',
        'message_payload',
        'is_compliant_hours',
        'gateway_status',
    ];

    protected function casts(): array
    {
        return [
            'is_compliant_hours' => 'boolean',
            'created_at'         => 'datetime',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(CollectionCase::class, 'collection_case_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }
}