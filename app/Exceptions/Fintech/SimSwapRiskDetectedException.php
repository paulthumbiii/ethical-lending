<?php

declare(strict_types=1);

namespace App\Exceptions\Fintech;

use Exception;

final class SimSwapRiskDetectedException extends Exception
{
    public function __construct(
        public readonly string $phoneNumber,
        public readonly string $lastSwappedAt,
        public readonly int $hoursRemainingInQuarantine,
        string $message = ''
    ) {
        $msg = $message !== '' ? $message : sprintf(
            'SIM-Swap Fraud Risk Detected on MSISDN [%s]: Line was swapped at [%s]. Under CBK safety guidelines, payout is frozen with %d hour(s) remaining in the 48-hour quarantine window.',
            $phoneNumber,
            $lastSwappedAt,
            $hoursRemainingInQuarantine
        );

        parent::__construct($msg);
    }
}