<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AccountingPeriod extends Model
{
    protected $fillable = [
        'period_name',
        'start_date',
        'end_date',
        'is_locked',
    ];

    protected function casts(): array
    {
        return [
            'is_locked'  => 'boolean',
            'start_date' => 'date',
            'end_date'   => 'date',
        ];
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'accounting_period_id');
    }
}