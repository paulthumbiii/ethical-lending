<?php

declare(strict_types=1);

namespace App\Exceptions\Fintech;

use Exception;

final class DuplicateWebhookException extends Exception
{
    public function __construct(
        public readonly string $transactionId,
        public readonly string $provider = 'SAFARICOM_MPESA',
        string $message = ''
    ) {
        $msg = $message !== '' ? $message : sprintf(
            'Duplicate webhook received from [%s] for Transaction ID [%s]. Ingestion skipped to prevent double-crediting.',
            $provider,
            $transactionId
        );

        parent::__construct($msg);
    }
}