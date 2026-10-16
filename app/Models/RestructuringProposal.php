<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RestructuringProposal extends Model
{
    protected $fillable = [
        'loan_id',
        'proposal_code',
        'status',
        'forgiven_interest',
        'forgiven_penalties',
        'capitalized_principal',
        'new_interest_rate',
        'tenure_extension_days',
        'repayment_ratio_snapshot',
        'expires_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'forgiven_interest'        => 'decimal:2',
            'forgiven_penalties'       => 'decimal:2',
            'capitalized_principal'    => 'decimal:2',
            'new_interest_rate'        => 'decimal:2',
            'repayment_ratio_snapshot' => 'decimal:2',
            'expires_at'               => 'datetime',
            'accepted_at'              => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(RestructuringSchedule::class, 'restructuring_proposal_id');
    }
}