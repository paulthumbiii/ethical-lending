<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class UssdSession extends Model
{
    protected $fillable = [
        'session_id',
        'phone_number',
        'service_code',
        'current_menu_node',
        'session_data',
    ];

    protected function casts(): array
    {
        return [
            'session_data' => 'array',
        ];
    }
}