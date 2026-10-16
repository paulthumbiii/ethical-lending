<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CollectionCase extends Model
{
    protected $fillable = [
        'loan_id',
        'borrower_id',
        'dpd_bucket',
        'assigned_agent_user_id',
        'case_status',
        'last_contacted_at',
    ];

    protected function casts(): array
    {
        return [
            'last_contacted_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_user_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(CollectionAction::class, 'collection_case_id');
    }

    public function promisesToPay(): HasMany
    {
        return $this->hasMany(PromiseToPay::class, 'collection_case_id');
    }
}