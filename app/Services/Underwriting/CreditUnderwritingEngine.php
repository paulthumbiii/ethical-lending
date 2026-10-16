<?php

declare(strict_types=1);

namespace App\Services\Underwriting;

use App\Enums\RiskBand;
use App\Models\Borrower;
use App\Models\BorrowerFinancialProfile;
use App\Models\CrbInquiryRecord;
use App\Models\CreditRuleEvaluation;
use App\Models\LoanApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class CreditUnderwritingEngine
{
    /**
     * Evaluates a loan application against statutory and risk underwriting rules.
     * Assigns risk bands, calculates credit limits, and records detailed rule logs.
     *
     * @param int $applicationId
     * @return array Summary of underwriting decision and assigned terms
     */
    public function evaluateApplication(int $applicationId): array
    {
        return DB::transaction(function () use ($applicationId) {
            /** @var LoanApplication $application */
            $application = LoanApplication::where('id', $applicationId)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Borrower $borrower */
            $borrower = Borrower::where('id', $application->borrower_id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var BorrowerFinancialProfile|null $profile */
            $profile = BorrowerFinancialProfile::where('borrower_id', $borrower->id)->first();

            /** @var CrbInquiryRecord|null $latestCrb */
            $latestCrb = CrbInquiryRecord::where('borrower_id', $borrower->id)
                ->orderByDesc('id')
                ->first();

            $passedCount = 0;
            $failedCount = 0;

            // 1. Hard Rule: Borrower Blacklist Inspection
            $isBlacklisted = $borrower->is_blacklisted;
            $this->recordRuleEvaluation(
                $application->id,
                'RULE_01_BLACKLIST_CHECK',
                'Borrower Fraud & Blacklist Inspection',
                ! $isBlacklisted ? 'PASSED' : 'FAILED',
                $isBlacklisted ? 'Blacklisted' : 'Clean',
                'Clean'
            );
            $isBlacklisted ? $failedCount++ : $passedCount++;

            // 2. Hard Rule: CRB Defaulted Accounts Audit
            $defaultedAccounts = $latestCrb ? $latestCrb->defaulted_accounts_count : 0;
            $crbPassed = $defaultedAccounts === 0;
            $this->recordRuleEvaluation(
                $application->id,
                'RULE_02_CRB_DEFAULTS',
                'CRB Active NPL/Defaulted Accounts Check',
                $crbPassed ? 'PASSED' : 'FAILED',
                (string) $defaultedAccounts,
                '0'
            );
            $crbPassed ? $passedCount++ : $failedCount++;

            // 3. Hard Rule: Debt-To-Income (DTI) Ratio Guard (Max 50.00%)
            $monthlyIncome = $profile ? (string) $profile->monthly_income : '0.00';
            $monthlyExpenses = $profile ? (string) $profile->monthly_expenses : '0.00';
            $existingDebts = $profile ? (string) $profile->existing_debt_obligations : '0.00';

            $totalObligations = bcadd($monthlyExpenses, $existingDebts, 2);
            $dtiRatio = bccomp($monthlyIncome, '0.00', 2) > 0
                ? bcdiv($totalObligations, $monthlyIncome, 4)
                : '1.0000';

            $dtiPassed = bccomp($dtiRatio, '0.5000', 4) <= 0;
            $dtiPercentage = bcmul($dtiRatio, '100', 2).'%';

            $this->recordRuleEvaluation(
                $application->id,
                'RULE_03_DTI_RATIO',
                'Debt-To-Income Ratio Below 50%',
                $dtiPassed ? 'PASSED' : 'FAILED',
                $dtiPercentage,
                '<= 50.00%'
            );
            $dtiPassed ? $passedCount++ : $failedCount++;

            // 4. Hard Rule: M-Pesa Cash Flow Capacity Test (Min 1.5x Requested Principal)
            $mpesaInflow = $profile ? (string) $profile->mpesa_average_monthly_inflow : '0.00';
            $requestedPrincipal = (string) $application->requested_principal;

            $requiredInflow = bcmul($requestedPrincipal, '1.50', 2);
            $inflowPassed = bccomp($mpesaInflow, $requiredInflow, 2) >= 0;

            $this->recordRuleEvaluation(
                $application->id,
                'RULE_04_MPESA_INFLOW_CAPACITY',
                'M-Pesa Average Monthly Inflow Capacity',
                $inflowPassed ? 'PASSED' : 'FAILED',
                "KES {$mpesaInflow}",
                ">= KES {$requiredInflow}"
            );
            $inflowPassed ? $passedCount++ : $failedCount++;

            // Decision Matrix & Term Assignment
            if ($failedCount > 0) {
                $status = 'rejected';
                $riskBand = RiskBand::D;
                $approvedLimit = '0.00';
                $rejectionReason = "Failed {$failedCount} underwriting risk evaluation rules.";
            } else {
                $status = 'approved_pending_checker';
                $rejectionReason = null;

                // Score-Based Risk Band Assignment
                $crbScore = $latestCrb ? $latestCrb->credit_score : 600;

                if ($crbScore >= 750) {
                    $riskBand = RiskBand::A;
                    $approvedLimit = bcmul($requestedPrincipal, '1.25', 2);
                } elseif ($crbScore >= 650) {
                    $riskBand = RiskBand::B;
                    $approvedLimit = $requestedPrincipal;
                } else {
                    $riskBand = RiskBand::C;
                    $approvedLimit = bcmul($requestedPrincipal, '0.75', 2);
                }
            }

            // Update Application state
            $application->update([
                'status' => $status,
                'rejection_reason' => $rejectionReason,
            ]);

            // Update Borrower risk profile and credit limit
            $borrower->update([
                'risk_band' => $riskBand,
                'credit_limit' => $approvedLimit,
            ]);

            Log::channel('fintech')->info("Underwriting complete for Application #{$application->id}: Status = {$status}, Risk Band = {$riskBand->value}, Approved Limit = KES {$approvedLimit}");

            return [
                'application_id' => $application->id,
                'status' => $status,
                'risk_band' => $riskBand->value,
                'approved_limit' => $approvedLimit,
                'rules_passed' => $passedCount,
                'rules_failed' => $failedCount,
            ];
        });
    }

    private function recordRuleEvaluation(
        int $applicationId,
        string $identifier,
        string $description,
        string $status,
        string $actualValue,
        string $thresholdValue
    ): void {
        CreditRuleEvaluation::create([
            'loan_application_id' => $applicationId,
            'rule_identifier' => $identifier,
            'rule_description' => $description,
            'status' => $status,
            'actual_value' => $actualValue,
            'threshold_value' => $thresholdValue,
        ]);
    }
}