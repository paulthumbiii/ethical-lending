<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MpesaStkRequest extends Model
{
    protected $fillable = [
        'loan_id',
        'merchant_request_id',
        'checkout_request_id',
        'phone_number',
        'amount',
        'mpesa_receipt_number',
        'result_code',
        'result_desc',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }
}