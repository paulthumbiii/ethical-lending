<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Enums\LoanStatus;
use App\Models\CbkRegulatoryReturn;
use App\Models\InDuplumTracking;
use App\Models\Loan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class CbkStatutoryReturnService
{
    /**
     * Compiles monthly statutory return figures for Central Bank of Kenya (CBK) off-site reporting.
     *
     * @param string $reportingPeriod YYYY-MM format
     * @return CbkRegulatoryReturn
     */
    public function generateMonthlyReturn(string $reportingPeriod): CbkRegulatoryReturn
    {
        return DB::transaction(function () use ($reportingPeriod) {
            $totalActiveLoans = Loan::whereIn('status', [LoanStatus::DISBURSED, LoanStatus::RESTRUCTURED])->count();
            $totalDefaultedLoans = Loan::where('status', LoanStatus::DEFAULTED)->count();

            $totalActivePrincipal = Loan::whereIn('status', [LoanStatus::DISBURSED, LoanStatus::RESTRUCTURED])
                ->sum('principal_disbursed');

            $inDuplumLockedCount = Loan::where('is_in_duplum_locked', true)->count();
            $totalInDuplumCappedInterest = InDuplumTracking::sum('capped_interest_amount');

            $dataPayload = [
                'reporting_period' => $reportingPeriod,
                'total_active_loans' => $totalActiveLoans,
                'total_defaulted_loans' => $totalDefaultedLoans,
                'total_active_principal' => (string) $totalActivePrincipal,
                'in_duplum_locked_loans_count' => $inDuplumLockedCount,
                'total_in_duplum_capped_interest' => (string) $totalInDuplumCappedInterest,
                'generated_at' => now()->toIso8601String(),
            ];

            /** @var CbkRegulatoryReturn $return */
            $return = CbkRegulatoryReturn::create([
                'reporting_period' => $reportingPeriod,
                'return_type' => 'MONTHLY_PORTFOLIO_HEALTH',
                'payload' => $dataPayload,
                'submitted_at' => now(),
            ]);

            Log::channel('fintech')->info("CBK Monthly Statutory Return compiled for period {$reportingPeriod}");

            return $return;
        });
    }
}