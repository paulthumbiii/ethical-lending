<?php

declare(strict_types=1);

namespace App\Exceptions\Fintech;

use Exception;

final class InvalidPaymentAmountException extends Exception
{
    public function __construct(
        public readonly string $attemptedAmount,
        string $message = ''
    ) {
        $msg = $message !== '' ? $message : sprintf(
            'Invalid payment amount received: [%s]. Repayments must be strictly positive numeric values greater than zero.',
            $attemptedAmount
        );

        parent::__construct($msg);
    }
}