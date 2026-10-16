<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CrbSubmissionLog extends Model
{
    protected $fillable = [
        'batch_month',
        'bureau_name',
        'total_records_submitted',
        'performing_records',
        'defaulted_records',
        'file_path',
        'status',
    ];
}