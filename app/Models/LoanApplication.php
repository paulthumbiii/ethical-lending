<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class LoanApplication extends Model
{
    protected $fillable = [
        'application_number',
        'borrower_id',
        'loan_product_id',
        'requested_principal',
        'requested_tenure_days',
        'status',
        'underwriter_id',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'requested_principal' => 'decimal:2',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }

    public function underwriter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'underwriter_id');
    }

    public function ruleEvaluations(): HasMany
    {
        return $this->hasMany(CreditRuleEvaluation::class, 'loan_application_id');
    }

    public function loan(): HasOne
    {
        return $this->hasOne(Loan::class, 'loan_application_id');
    }
}