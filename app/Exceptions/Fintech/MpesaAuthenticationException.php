<?php

declare(strict_types=1);

namespace App\Exceptions\Fintech;

use Exception;

final class MpesaAuthenticationException extends Exception
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $responseBody,
        string $message = ''
    ) {
        $msg = $message !== '' ? $message : sprintf(
            'Failed to authenticate with Safaricom Daraja API [HTTP %d]: %s',
            $statusCode,
            $responseBody
        );

        parent::__construct($msg);
    }
}