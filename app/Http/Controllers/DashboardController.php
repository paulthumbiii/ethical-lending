<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\LoanStatus;
use App\Models\EclProvision;
use App\Models\InDuplumTracking;
use App\Models\Loan;
use App\Models\LoanLedgerEntry;
use App\Models\MakerCheckerRequest;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;

final class DashboardController extends Controller
{
    /**
     * Institutional Command Center for Lender Staff (Admins, Underwriters, Finance, Collections).
     * Phase 11.5c: the unused borrower() method was removed; the borrower home is Borrower\PortalController.
     */
    public function lender(): View
    {
        $now = CarbonImmutable::now();

        // 1. Total Active Capital Disbursed (KES)
        $activeLoans = Loan::query()->whereIn('status', [
            LoanStatus::DISBURSED,
            LoanStatus::RESTRUCTURED,
            LoanStatus::DEFAULTED,
        ]);

        $totalDisbursed = (string) $activeLoans->sum('principal_disbursed');

        // 2. Portfolio at Risk (PAR 30): Active principal overdue > 30 days
        $par30Principal = (string) Loan::query()
            ->whereIn('status', [LoanStatus::DISBURSED, LoanStatus::DEFAULTED, LoanStatus::RESTRUCTURED])
            ->where('due_date', '<', $now->subDays(30))
            ->sum('principal_disbursed');

        $par30Percentage = bccomp($totalDisbursed, '0.00', 2) > 0
            ? bcmul(bcdiv($par30Principal, $totalDisbursed, 4), '100.00', 2)
            : '0.00';

        // 3. Total IFRS 9 Expected Credit Loss (ECL) Reserves
        $totalEclReserve = (string) EclProvision::query()
            ->where('reporting_date', EclProvision::max('reporting_date'))
            ->sum('calculated_ecl');

        // 4. Section 44A In-Duplum Freezes Count
        $inDuplumFrozenCount = Loan::query()->where('is_in_duplum_locked', true)->count();

        // 5. Dual-Control Maker-Checker Requests (Pending Queue)
        $pendingMakerCheckerCount = MakerCheckerRequest::query()->where('status', 'pending')->count();
        $pendingMakerCheckerQueue = MakerCheckerRequest::query()
            ->with('maker')
            ->where('status', 'pending')
            ->latest('id')
            ->limit(5)
            ->get();

        // 6. Regulatory In-Duplum & Default Watchlist
        $inDuplumWatchlist = InDuplumTracking::query()
            ->with(['loan.borrower'])
            ->latest('id')
            ->limit(5)
            ->get();

        // 7. Live Append-Only Financial Ledger Feed
        $recentLedgerEntries = LoanLedgerEntry::query()
            ->with('loan.borrower')
            ->latest('id')
            ->limit(6)
            ->get();

        return view('lender.dashboard', [
            'totalDisbursed'           => $totalDisbursed,
            'par30Principal'           => $par30Principal,
            'par30Percentage'          => $par30Percentage,
            'totalEclReserve'          => $totalEclReserve,
            'inDuplumFrozenCount'      => $inDuplumFrozenCount,
            'pendingMakerCheckerCount' => $pendingMakerCheckerCount,
            'pendingMakerCheckerQueue' => $pendingMakerCheckerQueue,
            'inDuplumWatchlist'        => $inDuplumWatchlist,
            'recentLedgerEntries'      => $recentLedgerEntries,
        ]);
    }
}