<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Ifrs9LoanStage extends Model
{
    protected $fillable = [
        'loan_id',
        'current_stage',
        'previous_stage',
        'sicr_flag',
        'transition_reason',
        'evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'sicr_flag'    => 'boolean',
            'evaluated_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }
}