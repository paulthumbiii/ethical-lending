<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ReconciliationBatch extends Model
{
    protected $fillable = [
        'batch_date',
        'total_mpesa_records',
        'total_system_records',
        'matched_records',
        'unreconciled_discrepancies',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'batch_date' => 'date',
        ];
    }
}