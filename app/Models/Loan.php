<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Loan extends Model
{
    protected $fillable = [
        'loan_number',
        'loan_application_id',
        'borrower_id',
        'loan_product_id',
        'principal_disbursed',
        'annual_interest_rate',
        'tenure_days',
        'status',
        'disbursed_at',
        'due_date',
        'defaulted_at',
        'is_in_duplum_locked',
    ];

    protected function casts(): array
    {
        return [
            'principal_disbursed'  => 'decimal:2',
            'annual_interest_rate' => 'decimal:2',
            'status'               => LoanStatus::class,
            'is_in_duplum_locked'  => 'boolean',
            'disbursed_at'         => 'datetime',
            'due_date'             => 'datetime',
            'defaulted_at'         => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class, 'loan_application_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LoanLedgerEntry::class, 'loan_id');
    }

    public function inDuplumTracking(): HasOne
    {
        return $this->hasOne(InDuplumTracking::class, 'loan_id');
    }

    public function amortizationSchedules(): HasMany
    {
        return $this->hasMany(AmortizationSchedule::class, 'loan_id');
    }

    public function restructuringProposals(): HasMany
    {
        return $this->hasMany(RestructuringProposal::class, 'loan_id');
    }

    public function collectionCase(): HasOne
    {
        return $this->hasOne(CollectionCase::class, 'loan_id');
    }

    public function legalContract(): HasOne
    {
        return $this->hasOne(LoanLegalContract::class, 'loan_id');
    }

    public function writeOff(): HasOne
    {
        return $this->hasOne(LoanWriteOff::class, 'loan_id');
    }
    public function waterfallLogs(): HasMany
{
    return $this->hasMany(PaymentWaterfallLog::class);
}
}