<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MpesaStatementUpload extends Model
{
    protected $fillable = [
        'borrower_id',
        'file_path',
        'pdf_password_hash',
        'parsing_status',
        'total_inflows_detected',
        'total_outflows_detected',
        'gambling_transaction_count',
        'fuliza_deduction_count',
        'parsed_at',
    ];

    protected function casts(): array
    {
        return [
            'total_inflows_detected'  => 'decimal:2',
            'total_outflows_detected' => 'decimal:2',
            'parsed_at'               => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }
}