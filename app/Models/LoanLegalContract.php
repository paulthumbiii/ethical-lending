<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LoanLegalContract extends Model
{
    protected $fillable = [
        'loan_id',
        'contract_pdf_path',
        'contract_sha256_hash',
        'digital_signature_timestamp',
        'qr_verification_code',
    ];

    protected function casts(): array
    {
        return [
            'digital_signature_timestamp' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }
}