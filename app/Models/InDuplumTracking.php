<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InDuplumTracking extends Model
{
    protected $table = 'in_duplum_tracking';

    protected $fillable = [
        'loan_id',
        'defaulted_at',
        'default_principal_snapshot',
        'total_post_default_interest',
        'total_post_default_penalties',
        'statutory_cap_limit',
        'is_frozen',
        'frozen_at',
    ];

    protected function casts(): array
    {
        return [
            'default_principal_snapshot'  => 'decimal:2',
            'total_post_default_interest' => 'decimal:2',
            'total_post_default_penalties'=> 'decimal:2',
            'statutory_cap_limit'         => 'decimal:2',
            'is_frozen'                   => 'boolean',
            'defaulted_at'                => 'datetime',
            'frozen_at'                   => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }
}