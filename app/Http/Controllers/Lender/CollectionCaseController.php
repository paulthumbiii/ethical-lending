<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lender;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\CollectionsWorkbenchFilterRequest;
use App\Http\Requests\StorePromiseToPayRequest;
use App\Models\AuditTrail;
use App\Models\CollectionCase;
use App\Models\PromiseToPay;
use App\Models\User;
use App\Services\Servicing\OverdueDetectionService;
use App\Services\Servicing\PromiseToPayService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class CollectionCaseController extends Controller
{
    public function show(Request $request, CollectionCase $collectionCase, OverdueDetectionService $detector): View
    {
        $now = CarbonImmutable::now();
        $today = $now->setTimezone('Africa/Nairobi')->toDateString();

        $collectionCase->load([
            'loan.borrower',
            'loan.inDuplumTracking',
            'promisesToPay' => fn ($relation) => $relation->orderByDesc('id'),
            'actions'       => fn ($relation) => $relation->orderByDesc('id')->limit(50),
        ]);

        $loan = $collectionCase->loan;
        $days = $loan !== null ? $detector->daysPastDue($loan, $now) : 0;
        $since = ($loan !== null && $days > 0) ? $detector->overdueSince($loan, $now) : null;

        $live = $collectionCase->promisesToPay->first(
            fn ($promise): bool => $promise->status === 'pending'
                && $promise->promised_date->toDateString() >= $today
        );

        return view('lender.collections.show', [
            'case'       => $collectionCase,
            'loan'       => $loan,
            'days'       => $days,
            'since'      => $since,
            'live'       => $live,
            'recordedBy' => $this->recordedBy($collectionCase),
            'canRecord'  => (bool) $request->user()?->hasAnyRole(UserRole::promiseRecorders()),
            'buckets'    => CollectionsWorkbenchFilterRequest::BUCKETS,
            'minDate'    => $today,
            'maxDate'    => $now->setTimezone('Africa/Nairobi')->addDays(PromiseToPayService::MAX_DAYS_AHEAD)->toDateString(),
        ]);
    }

    public function storePromise(
        StorePromiseToPayRequest $request,
        CollectionCase $collectionCase,
        PromiseToPayService $service
    ): RedirectResponse {
        $back = redirect()->route('lender.collections.show', $collectionCase);

        try {
            $service->record(
                $collectionCase,
                (string) $request->validated('amount'),
                (string) $request->validated('promised_date'),
                $request->user()?->id
            );
        } catch (RuntimeException $e) {
            return $back->withInput()->with('error', $e->getMessage());
        }

        return $back->with('status', 'Promise to pay recorded.');
    }

    /**
     * Who recorded each promise, read from the audit row written when it was saved.
     *
     * @return array<int, string> promise id => label
     */
    private function recordedBy(CollectionCase $case): array
    {
        $promiseIds = $case->promisesToPay->pluck('id')->all();

        if ($promiseIds === []) {
            return [];
        }

        $audits = AuditTrail::query()
            ->where('action', 'PROMISE_TO_PAY_RECORDED')
            ->where('auditable_type', PromiseToPay::class)
            ->whereIn('auditable_id', $promiseIds)
            ->get(['auditable_id', 'user_id']);

        $userIds = $audits->pluck('user_id')->filter()->unique()->values()->all();

        $users = User::query()->with('roles')->whereIn('id', $userIds)->get()->keyBy('id');

        $labels = [];

        foreach ($audits as $audit) {
            $userId = $audit->user_id;

            if ($userId === null || ! $users->has($userId)) {
                $labels[(int) $audit->auditable_id] = 'Not recorded';
            } elseif ($users->get($userId)->hasRole(UserRole::Borrower->value)) {
                $labels[(int) $audit->auditable_id] = 'Borrower (self-service)';
            } else {
                $labels[(int) $audit->auditable_id] = (string) $users->get($userId)->name;
            }
        }

        return $labels;
    }
}