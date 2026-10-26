<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\Models\BorrowerNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BorrowerNotificationService
{
    /**
     * Never throws. Call it from inside the transaction that made the change:
     * if that transaction rolls back, the message rolls back with it.
     */
    public function notify(int $borrowerId, string $kind, string $title, string $body): void
    {
        try {
            DB::transaction(function () use ($borrowerId, $kind, $title, $body): void {
                BorrowerNotification::create([
                    'borrower_id' => $borrowerId,
                    'kind'        => mb_substr($kind, 0, 50),
                    'title'       => mb_substr($title, 0, 120),
                    'body'        => mb_substr($body, 0, 500),
                ]);
            });
        } catch (Throwable $e) {
            Log::channel('fintech')->warning(
                'Borrower alert not saved (kind ' . $kind . ', borrower ' . $borrowerId . '): ' . $e->getMessage()
            );
        }
    }
}