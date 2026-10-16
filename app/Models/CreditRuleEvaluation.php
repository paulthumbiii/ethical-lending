<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CreditRuleEvaluation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'loan_application_id',
        'rule_identifier',
        'rule_description',
        'status',
        'actual_value',
        'threshold_value',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class, 'loan_application_id');
    }
}