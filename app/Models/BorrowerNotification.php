<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BorrowerNotification extends Model
{
    protected $fillable = [
        'borrower_id',
        'kind',
        'title',
        'body',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }

    /** Number of unread alerts for the header badge. */
    public static function unreadCountFor(?int $borrowerId): int
    {
        if ($borrowerId === null) {
            return 0;
        }

        return self::query()
            ->where('borrower_id', $borrowerId)
            ->whereNull('read_at')
            ->count();
    }
}