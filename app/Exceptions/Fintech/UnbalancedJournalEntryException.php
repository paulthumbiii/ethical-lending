<?php

declare(strict_types=1);

namespace App\Exceptions\Fintech;

use Exception;

final class UnbalancedJournalEntryException extends Exception
{
    public function __construct(
        public readonly string $totalDebits,
        public readonly string $totalCredits,
        string $message = ''
    ) {
        $msg = $message !== '' ? $message : sprintf(
            'Unbalanced journal entry rejected: Total debits [KES %s] do not equal total credits [KES %s]. Double-entry invariant violated.',
            $totalDebits,
            $totalCredits
        );

        parent::__construct($msg);
    }
}