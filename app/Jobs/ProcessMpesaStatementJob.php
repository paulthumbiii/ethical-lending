<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MpesaStatementUpload;
use App\Services\Underwriting\MpesaStatementParserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessMpesaStatementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public readonly int $uploadId,
        public readonly string $nationalId
    ) {}

    /**
     * Executes the asynchronous statement parsing job.
     */
    public function handle(MpesaStatementParserService $parserService): void
    {
        Log::channel('fintech')->info("Starting queued M-Pesa statement parsing for Upload #{$this->uploadId}");

        $parserService->parseStatement($this->uploadId, $this->nationalId);
    }

    /**
     * Handle job execution failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::channel('fintech')->critical("ProcessMpesaStatementJob failed permanently for Upload #{$this->uploadId}: " . $exception->getMessage(), [
            'exception' => $exception,
        ]);

        MpesaStatementUpload::where('id', $this->uploadId)->update([
            'parsing_status' => 'failed',
        ]);
    }
}