<?php

declare(strict_types=1);

namespace App\Enums;

enum StatementReviewStatus: string
{
    case AwaitingReview = 'awaiting_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function borrowerLabel(): string
    {
        return match ($this) {
            self::AwaitingReview => 'Being checked',
            self::Accepted => 'Accepted',
            self::Rejected => 'Please upload again',
            self::Superseded => 'Replaced by a newer upload',
        };
    }
}