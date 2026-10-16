<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class CrbReportExport extends Model
{
    use HasFactory;

    protected $fillable = [
        'bureau_name',
        'export_reference',
        'records_count',
        'payload_summary',
        'exported_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_summary' => 'array',
            'exported_at' => 'datetime',
        ];
    }
}