<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Financial\Mpesa\MpesaWebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessMpesaCallbackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public readonly string $sourceType, // 'C2B' or 'STK'
        public readonly int $recordId
    ) {}

    public function handle(MpesaWebhookService $service): void
    {
        $service->processRepayment($this->sourceType, $this->recordId);
    }
}