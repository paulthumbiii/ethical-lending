<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Loan;
use App\Services\Financial\Mpesa\B2cDisbursementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DispatchMpesaB2cDisbursementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public readonly Loan $loan) {}

    public function handle(B2cDisbursementService $service): void
    {
        $service->initiateDisbursement($this->loan);
    }
}