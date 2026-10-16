<?php

declare(strict_types=1);

namespace App\Exceptions\Fintech;

use Exception;

final class AccountingPeriodClosedException extends Exception
{
    public function __construct(
        public readonly string $periodName,
        string $message = ''
    ) {
        $msg = $message !== '' ? $message : sprintf(
            'Accounting period [%s] is locked and closed. No new journal postings permitted.',
            $periodName
        );

        parent::__construct($msg);
    }
}