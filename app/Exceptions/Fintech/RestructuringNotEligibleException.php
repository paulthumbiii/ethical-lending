<?php

declare(strict_types=1);

namespace App\Exceptions\Fintech;

use Exception;

final class RestructuringNotEligibleException extends Exception
{
    public function __construct(
        public readonly int $loanId,
        public readonly string $repaymentRatio,
        public readonly string $reason,
        string $message = ''
    ) {
        $msg = $message !== '' ? $message : sprintf(
            'Loan #%d is not eligible for restructuring [Repayment Ratio: %s]. Reason: %s',
            $loanId,
            $repaymentRatio,
            $reason
        );

        parent::__construct($msg);
    }
}