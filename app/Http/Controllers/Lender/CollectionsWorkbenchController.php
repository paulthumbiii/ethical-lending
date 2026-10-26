<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lender;

use App\Http\Controllers\Controller;
use App\Http\Requests\CollectionsWorkbenchFilterRequest;
use App\Models\CollectionCase;
use App\Models\InDuplumTracking;
use App\Models\Loan;
use App\Models\RegulatoryCooldownLock;
use App\Services\Servicing\FairCollectionsService;
use App\Services\Servicing\OverdueDetectionService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

final class CollectionsWorkbenchController extends Controller
{
    private const CASE_LIMIT = 100;

    public function index(
        CollectionsWorkbenchFilterRequest $request,
        FairCollectionsService $fair,
        OverdueDetectionService $detector
    ): View {
        $now = CarbonImmutable::now();
        $today = $now->toDateString();

        // Today in Nairobi, expressed in the app's own timezone for the query.
        $dayStart = $now->setTimezone('Africa/Nairobi')->startOfDay()->setTimezone((string) config('app.timezone'));
        $dayEnd = $now->setTimezone('Africa/Nairobi')->endOfDay()->setTimezone((string) config('app.timezone'));

        // Same definition of "active" that FairCollectionsService uses.
        $activeLocks = fn (): Builder => RegulatoryCooldownLock::query()
            ->select('borrower_id')
            ->where('lock_type', 'COLLECTIONS_SMS')
            ->where('locked_until', '>', $now);

        $activePtp = fn ($query) => $query
            ->where('status', 'pending')
            ->where('promised_date', '>=', $today);

        $stats = [
            'cases'           => CollectionCase::query()->count(),
            'cooldown'        => CollectionCase::query()->whereIn('borrower_id', $activeLocks())->count(),
            'ptp'             => CollectionCase::query()->whereHas('promisesToPay', $activePtp)->count(),
            'frozen'          => InDuplumTracking::query()->where('is_frozen', true)->count(),
            'defaulted_today' => Loan::query()->whereBetween('defaulted_at', [$dayStart, $dayEnd])->count(),
        ];

        $filters = [
            'bucket'   => $request->validated('bucket'),
            'cooldown' => $request->validated('cooldown'),
            'ptp'      => $request->validated('ptp'),
        ];

        $query = CollectionCase::query()->with([
            'loan.borrower',
            'loan.inDuplumTracking',
            'promisesToPay' => fn ($relation) => $activePtp($relation)->orderBy('promised_date'),
        ]);

        if ($filters['bucket'] !== null) {
            $query->where('dpd_bucket', $filters['bucket']);
        }

        if ($filters['cooldown'] === 'active') {
            $query->whereIn('borrower_id', $activeLocks());
        } elseif ($filters['cooldown'] === 'none') {
            $query->whereNotIn('borrower_id', $activeLocks());
        }

        if ($filters['ptp'] === 'active') {
            $query->whereHas('promisesToPay', $activePtp);
        } elseif ($filters['ptp'] === 'none') {
            $query->whereDoesntHave('promisesToPay', $activePtp);
        }

        $matching = (clone $query)->count();

        // Descending bucket codes puts the most overdue first (90_PLUS, 61_90, 31_60, 1_30).
        $cases = $query
            ->orderByDesc('dpd_bucket')
            ->orderByDesc('id')
            ->limit(self::CASE_LIMIT)
            ->get();

        // Same day count the overdue command uses, so screen and detection agree.
        $overdue = [];
        foreach ($cases as $case) {
            $loan = $case->loan;
            $days = $loan !== null ? $detector->daysPastDue($loan, $now) : 0;

            $overdue[$case->id] = [
                'days'  => $days,
                'since' => ($loan !== null && $days > 0) ? $detector->overdueSince($loan, $now) : null,
            ];
        }

        $locks = RegulatoryCooldownLock::query()
            ->where('lock_type', 'COLLECTIONS_SMS')
            ->where('locked_until', '>', $now)
            ->whereIn('borrower_id', $cases->pluck('borrower_id'))
            ->orderByDesc('locked_until')
            ->get()
            ->unique('borrower_id')
            ->keyBy('borrower_id');

        return view('lender.collections.index', [
            'cases'         => $cases,
            'overdue'       => $overdue,
            'locks'         => $locks,
            'stats'         => $stats,
            'filters'       => $filters,
            'matching'      => $matching,
            'caseLimit'     => self::CASE_LIMIT,
            'buckets'       => CollectionsWorkbenchFilterRequest::BUCKETS,
            'now'           => $now,
            'nowEat'        => $now->setTimezone('Africa/Nairobi')->format('H:i'),
            'withinHours'   => $fair->isWithinLegalContactHours($now),
            'hourStart'     => sprintf('%02d:00', FairCollectionsService::LEGAL_HOUR_START),
            'hourEnd'       => sprintf('%02d:00', FairCollectionsService::LEGAL_HOUR_END),
            'cooldownHours' => FairCollectionsService::COOLDOWN_HOURS,
        ]);
    }
}