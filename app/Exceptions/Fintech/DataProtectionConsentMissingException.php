<?php

declare(strict_types=1);

namespace App\Exceptions\Fintech;

use Exception;

final class DataProtectionConsentMissingException extends Exception
{
    public function __construct(string $identifier = '')
    {
        $message = 'Statutory consent required under Section 25 of the Kenya Data Protection Act 2019 was not granted.';
        if ($identifier !== '') {
            $message .= " Target Identifier: {$identifier}";
        }

        parent::__construct($message);
    }
}