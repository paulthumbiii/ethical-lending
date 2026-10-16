<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MpesaC2bCallback extends Model
{
    public $timestamps = false;

    protected $table = 'mpesa_c2b_callbacks';

    protected $fillable = [
        'transaction_type',
        'trans_id',
        'trans_time',
        'trans_amount',
        'business_short_code',
        'bill_ref_number',
        'msisdn',
        'first_name',
        'is_reconciled',
    ];

    protected function casts(): array
    {
        return [
            'trans_amount'  => 'decimal:2',
            'is_reconciled' => 'boolean',
            'created_at'    => 'datetime',
        ];
    }
}