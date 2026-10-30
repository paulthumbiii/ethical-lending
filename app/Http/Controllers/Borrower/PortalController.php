<?php

declare(strict_types=1);

namespace App\Http\Controllers\Borrower;

use App\Enums\LoanStatus;
use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\PromiseToPay;
use App\Services\Customer\BorrowerPortalService;
use App\Services\Servicing\OverdueDetectionService;
use App\Services\Servicing\PromiseToPayService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PortalController extends Controller
{
    public function __construct(
        private readonly BorrowerPortalService $portal,
        private readonly OverdueDetectionService $detector
    ) {
    }

    public function show(Request $request): View
    {
        $borrower = $request->user()?->borrower;
        $summary = $borrower !== null ? $this->portal->summary($borrower) : null;

        return view('borrower.dashboard', [
            'borrower' => $borrower,
            'portal'   => $summary,
            'promise'  => $this->promiseCard($summary),
        ]);
    }

    /**
     * Data for the "Tell us when you will pay" card, or null when the card should not show.
     *
     * @param  array<string, mixed>|null  $summary
     * @return array<string, mixed>|null
     */
    private function promiseCard(?array $summary): ?array
    {
        $loan = $summary['loan'] ?? null;

        if (! $loan instanceof Loan) {
            return null;
        }

        $statuses = [LoanStatus::DISBURSED, LoanStatus::RESTRUCTURED, LoanStatus::DEFAULTED];

        if (! in_array($loan->status, $statuses, true)) {
            return null;
        }

        $now = CarbonImmutable::now('Africa/Nairobi');

        if ($this->detector->daysPastDue($loan, $now) < 1) {
            return null;
        }

        $live = PromiseToPay::query()
            ->whereHas('case', fn ($query) => $query->where('loan_id', $loan->id))
            ->where('status', 'pending')
            ->where('promised_date', '>=', $now->toDateString())
            ->orderByDesc('id')
            ->first();

        return [
            'live'     => $live,
            'min'      => $now->toDateString(),
            'max'      => $now->addDays(PromiseToPayService::MAX_DAYS_AHEAD)->toDateString(),
            'max_days' => PromiseToPayService::MAX_DAYS_AHEAD,
        ];
    }
}