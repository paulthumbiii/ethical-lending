<?php

declare(strict_types=1);

namespace App\Exceptions\Fintech;

use Exception;

final class RegulatoryCooldownActiveException extends Exception
{
    public function __construct(
        public readonly int $borrowerId,
        public readonly string $lockedUntil,
        public readonly int $hoursRemaining,
        string $message = ''
    ) {
        $msg = $message !== '' ? $message : sprintf(
            'CBK Fair Collections Guard: Outreach blocked for Borrower #%d. Active 48-hour cooldown lock until [%s] (%d hour(s) remaining).',
            $borrowerId,
            $lockedUntil,
            $hoursRemaining
        );

        parent::__construct($msg);
    }
}