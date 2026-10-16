<?php

declare(strict_types=1);

namespace App\Exceptions\Fintech;

use Exception;

final class InDuplumCapExceededException extends Exception
{
    public function __construct(
        public readonly int $loanId,
        public readonly string $defaultPrincipalSnapshot,
        public readonly string $accumulatedPostDefaultCharges,
        public readonly string $attemptedCharge,
        string $message = ''
    ) {
        $msg = $message !== '' ? $message : sprintf(
            'Statutory In-Duplum breach on Loan #%d under Banking Act Section 44A: Snapshot principal is KES %s, current post-default accruals are KES %s. Attempted charge of KES %s would exceed statutory limit.',
            $loanId,
            $defaultPrincipalSnapshot,
            $accumulatedPostDefaultCharges,
            $attemptedCharge
        );

        parent::__construct($msg);
    }
}