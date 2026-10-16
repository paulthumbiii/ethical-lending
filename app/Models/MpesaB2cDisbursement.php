<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MpesaB2cDisbursement extends Model
{
    protected $table = 'mpesa_b2c_disbursements';

    protected $fillable = [
        'loan_id',
        'originator_conversation_id',
        'conversation_id',
        'transaction_id',
        'recipient_phone',
        'amount',
        'status',
        'response_code',
        'response_description',
        'disbursed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'       => 'decimal:2',
            'disbursed_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }
}