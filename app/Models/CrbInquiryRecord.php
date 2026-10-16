<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CrbInquiryRecord extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'borrower_id',
        'bureau_name',
        'credit_score',
        'open_accounts_count',
        'defaulted_accounts_count',
        'total_npl_amount',
        'inquiry_reference',
    ];

    protected function casts(): array
    {
        return [
            'total_npl_amount' => 'decimal:2',
            'created_at'       => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }
}