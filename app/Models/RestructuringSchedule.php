<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RestructuringSchedule extends Model
{
    protected $fillable = [
        'restructuring_proposal_id',
        'installment_number',
        'due_date',
        'installment_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'installment_amount' => 'decimal:2',
            'due_date'           => 'date',
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(RestructuringProposal::class, 'restructuring_proposal_id');
    }
}