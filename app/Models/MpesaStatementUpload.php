<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatementReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MpesaStatementUpload extends Model
{
    protected $fillable = [
        'borrower_id',
        'loan_application_id',
        'file_path',
        'original_name',
        'file_size',
        'file_sha256',
        'pdf_password_hash',
        'pdf_password_encrypted',
        'parsing_status',
        'total_inflows_detected',
        'total_outflows_detected',
        'gambling_transaction_count',
        'fuliza_deduction_count',
        'parsed_at',
        'review_status',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_notes',
        'flags',
        'valid_until',
    ];

    /** @var list<string> */
    protected $hidden = [
        'pdf_password_hash',
        'pdf_password_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'total_inflows_detected'  => 'decimal:2',
            'total_outflows_detected' => 'decimal:2',
            'parsed_at'               => 'datetime',
            'review_status'           => StatementReviewStatus::class,
            'reviewed_at'             => 'datetime',
            'flags'                   => 'array',
            'valid_until'             => 'date',
            'file_size'               => 'integer',
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}