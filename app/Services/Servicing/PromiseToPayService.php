<?php

declare(strict_types=1);

namespace App\Services\Servicing;

use App\Models\AuditTrail;
use App\Models\CollectionCase;
use App\Models\PaymentWaterfallLog;
use App\Models\PromiseToPay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class PromiseToPayService
{
    public const MAX_DAYS_AHEAD = 30;

    private const TZ = 'Africa/Nairobi';

    public function record(CollectionCase $case, string $amount, string $date, ?int $userId = null): PromiseToPay
    {
        if (preg_match('/^\d+(\.\d{1,2})?$/', $amount) !== 1 || bccomp($amount, '0.00', 2) <= 0) {
            throw new RuntimeException('The promised amount must be more than zero.');
        }

        $amount = bcadd($amount, '0', 2);
        $promisedDay = CarbonImmutable::createFromFormat('Y-m-d', $date, self::TZ);
        if ($promisedDay === false) {
            throw new RuntimeException('Use the date format YYYY-MM-DD.');
        }
        $promisedDay = $promisedDay->startOfDay();
        $today = CarbonImmutable::now(self::TZ)->startOfDay();

        if ($promisedDay->lessThan($today)) {
            throw new RuntimeException('The promised date cannot be in the past.');
        }

        if ($promisedDay->greaterThan($today->addDays(self::MAX_DAYS_AHEAD))) {
            throw new RuntimeException('The promised date is too far ahead.');
        }

        return DB::transaction(function () use ($case, $amount, $promisedDay, $today, $userId): PromiseToPay {
            $locked = CollectionCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

            if ($locked->case_status === 'closed') {
                throw new RuntimeException('This case is closed.');
            }

            $live = PromiseToPay::query()
                ->where('collection_case_id', $locked->id)
                ->where('status', 'pending')
                ->where('promised_date', '>=', $today->toDateString())
                ->exists();

            if ($live) {
                throw new RuntimeException('This case already has a promise to pay.');
            }

            $promise = PromiseToPay::create([
                'collection_case_id' => $locked->id,
                'promised_date'      => $promisedDay->toDateString(),
                'promised_amount'    => $amount,
                'status'             => 'pending',
            ]);

            $this->audit('PROMISE_TO_PAY_RECORDED', $promise->id, $userId, [
                'collection_case_id' => $locked->id,
                'promised_amount'    => $amount,
                'promised_date'      => $promisedDay->toDateString(),
            ]);

            return $promise;
        });
    }

    /**
     * @return array{checked: int, honored: int, broken: int, failed: int}
     */
    public function reviewAll(?CarbonImmutable $asOf = null): array
    {
        $stats = ['checked' => 0, 'honored' => 0, 'broken' => 0, 'failed' => 0];
        $now = $asOf ?? CarbonImmutable::now();
        $today = $now->setTimezone(self::TZ)->toDateString();

        PromiseToPay::query()
            ->where('status', 'pending')
            ->with('case')
            ->chunkById(200, function ($promises) use (&$stats, $now, $today): void {
                foreach ($promises as $promise) {
                    $stats['checked']++;

                    try {
                        $outcome = $this->review($promise, $now, $today);
                    } catch (Throwable $e) {
                        $stats['failed']++;
                        Log::channel('fintech')->error('Promise review failed for promise ' . $promise->id . ': ' . $e->getMessage());
                        continue;
                    }

                    if ($outcome === 'honored') {
                        $stats['honored']++;
                    } elseif ($outcome === 'broken') {
                        $stats['broken']++;
                    }
                }
            });

        return $stats;
    }

    private function review(PromiseToPay $promise, CarbonImmutable $now, string $today): string
    {
        $loanId = $promise->case?->loan_id;
        if ($loanId === null) {
            return 'pending';
        }

        $paid = '0.00';
        $amounts = PaymentWaterfallLog::query()
            ->where('loan_id', $loanId)
            ->where('created_at', '>=', $promise->created_at)
            ->pluck('total_amount_received');

        foreach ($amounts as $amount) {
            $paid = bcadd($paid, (string) $amount, 2);
        }

        if (bccomp($paid, (string) $promise->promised_amount, 2) >= 0) {
            $promise->update(['status' => 'honored', 'honored_at' => $now]);
            $this->audit('PROMISE_TO_PAY_HONORED', $promise->id, null, ['paid_since_promise' => $paid]);

            return 'honored';
        }

        if ($promise->promised_date->toDateString() < $today) {
            $promise->update(['status' => 'broken']);
            $this->audit('PROMISE_TO_PAY_BROKEN', $promise->id, null, [
                'paid_since_promise' => $paid,
                'promised_amount'    => (string) $promise->promised_amount,
            ]);

            return 'broken';
        }

        return 'pending';
    }

    /**
     * @param array<string, mixed> $new
     */
    private function audit(string $action, int $promiseId, ?int $userId, array $new): void
    {
        AuditTrail::create([
            'user_id'        => $userId ?? auth()->id(),
            'action'         => $action,
            'auditable_type' => PromiseToPay::class,
            'auditable_id'   => $promiseId,
            'old_values'     => null,
            'new_values'     => $new,
            'ip_address'     => request()->ip() ?? '127.0.0.1',
            'user_agent'     => request()->userAgent() ?? 'System/CLI',
        ]);
    }
}