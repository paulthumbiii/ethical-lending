<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BorrowerKycDocument extends Model
{
    /** @var array<string, string> */
    public const TYPES = [
        'id_front'            => 'National ID - front',
        'id_back'             => 'National ID - back',
        'kra_pin_certificate' => 'KRA PIN certificate (optional)',
    ];

    /** @var list<string> */
    public const REQUIRED = ['id_front', 'id_back'];

    protected $fillable = [
        'borrower_id',
        'document_type',
        'file_path',
        'verification_status',
        'verified_by_user_id',
        'verified_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'verification_status' => VerificationStatus::class,
            'verified_at'         => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}