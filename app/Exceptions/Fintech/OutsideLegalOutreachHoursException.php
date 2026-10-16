<?php

declare(strict_types=1);

namespace App\Exceptions\Fintech;

use Exception;

final class OutsideLegalOutreachHoursException extends Exception
{
    public function __construct(
        public readonly string $currentTimeEAT,
        string $message = ''
    ) {
        $msg = $message !== '' ? $message : sprintf(
            'Outreach rejected: Current time [%s EAT] is outside the statutory 08:00–18:00 window mandated by the CBK Digital Credit Providers Regulations 2022.',
            $currentTimeEAT
        );

        parent::__construct($msg);
    }
}