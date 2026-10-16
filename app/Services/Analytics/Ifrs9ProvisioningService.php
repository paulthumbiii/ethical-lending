<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\LoanStatus;
use App\Models\AuditTrail;
use App\Models\EclProvision;
use App\Models\Ifrs9LoanStage;
use App\Models\Loan;
use App\Models\LoanLedgerEntry;
use App\Models\OutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class Ifrs9ProvisioningService
{
    // Standard Loss Given Default (LGD) for unsecured digital credit in Kenya
    public const DEFAULT_LGD = '0.6000'; // 60% estimated loss

    /**
     * Classifies a loan into IFRS 9 Stage 1, Stage 2 (SICR), or Stage 3 (Defaulted).
     */
    public function classifyStage(Loan $loan, ?CarbonImmutable $asOfDate = null): int
    {
        $now = $asOfDate ?? CarbonImmutable::now();

        // 1. Stage 3: Explicit default status or >= 90 Days Past Due (DPD)
        if ($loan->status === LoanStatus::DEFAULTED || $loan->is_in_duplum_locked) {
            return 3;
        }

        if ($loan->due_date !== null && CarbonImmutable::parse($loan->due_date)->isPast()) {
            $dpd = (int) CarbonImmutable::parse($loan->due_date)->diffInDays($now);

            if ($dpd >= 90) {
                return 3; // Credit Impaired
            }

            if ($dpd >= 31) {
                return 2; // Underperforming (SICR)
            }
        }

        // 2. Stage 2: Restructured facilities automatically exhibit Significant Increase in Credit Risk
        if ($loan->status === LoanStatus::RESTRUCTURED) {
            return 2;
        }

        // 3. Stage 1: Performing (0 to 30 DPD)
        return 1;
    }

    /**
     * Computes the Expected Credit Loss for a single loan facility.
     * Formula: ECL = EAD * PD * LGD
     *
     * @return array{
     *     stage: int,
     *     ead: string,
     *     pd: string,
     *     lgd: string,
     *     calculated_ecl: string,
     *     sicr_flag: bool
     * }
     */
    public function calculateLoanEcl(Loan $loan, ?CarbonImmutable $asOfDate = null): array
    {
        $now = $asOfDate ?? CarbonImmutable::now();
        $stage = $this->classifyStage($loan, $now);

        // 1. Exposure at Default (EAD): Outstanding total balance from ledger
        $latest = LoanLedgerEntry::where('loan_id', $loan->id)->latest('id')->first();
        $ead = $latest !== null ? (string) $latest->total_running_balance : (string) $loan->principal_disbursed;

        // If loan has no balance owed, ECL is zero
        if (bccomp($ead, '0.00', 2) <= 0) {
            return [
                'stage'          => 1,
                'ead'            => '0.00',
                'pd'             => '0.0000',
                'lgd'            => self::DEFAULT_LGD,
                'calculated_ecl' => '0.00',
                'sicr_flag'      => false,
            ];
        }

        // 2. Probability of Default (PD) based on Stage and Risk Band
        $riskBand = $loan->borrower->risk_band->value ?? 'C';
        $pd = $this->resolveProbabilityOfDefault($stage, $riskBand);

        // 3. Loss Given Default (LGD)
        $lgd = self::DEFAULT_LGD;

        // 4. Mathematical ECL Calculation: EAD * PD * LGD
        // Step A: EAD * PD (scale: 4)
        $expectedExposure = bcmul($ead, $pd, 4);
        // Step B: (EAD * PD) * LGD (scale: 2)
        $calculatedEcl = bcmul($expectedExposure, $lgd, 2);

        return [
            'stage'          => $stage,
            'ead'            => $ead,
            'pd'             => $pd,
            'lgd'            => $lgd,
            'calculated_ecl' => $calculatedEcl,
            'sicr_flag'      => $stage === 2,
        ];
    }

    /**
     * Executes portfolio-wide IFRS 9 staging and ECL provision calculation.
     *
     * @return array{
     *     reporting_date: string,
     *     total_loans_evaluated: int,
     *     stage_1_provisions: string,
     *     stage_2_provisions: string,
     *     stage_3_provisions: string,
     *     total_ecl_reserve: string
     * }
     */
    public function computePortfolioEcl(?CarbonImmutable $asOfDate = null): array
    {
        return DB::transaction(function () use ($asOfDate): array {
            $now = $asOfDate ?? CarbonImmutable::now();
            $reportDate = $now->toDateString();

            $activeLoans = Loan::query()
                ->with(['borrower'])
                ->whereIn('status', ['disbursed', 'defaulted', 'watch', 'restructured'])
                ->get();

            $totalLoans = $activeLoans->count();
            $stage1Sum = '0.00';
            $stage2Sum = '0.00';
            $stage3Sum = '0.00';

            foreach ($activeLoans as $loan) {
                $eclData = $this->calculateLoanEcl($loan, $now);

                // 1. Record / Update IFRS 9 Staging
                $existingStage = Ifrs9LoanStage::where('loan_id', $loan->id)->first();
                $prevStage = $existingStage !== null ? $existingStage->current_stage : 1;

                Ifrs9LoanStage::updateOrCreate(
                    ['loan_id' => $loan->id],
                    [
                        'current_stage'     => $eclData['stage'],
                        'previous_stage'    => $prevStage,
                        'sicr_flag'         => $eclData['sicr_flag'],
                        'transition_reason' => sprintf('Evaluated on %s. DPD status and credit risk reassessed.', $reportDate),
                        'evaluated_at'      => $now,
                    ]
                );

                // 2. Persist ECL Provision Record
                EclProvision::create([
                    'loan_id'                => $loan->id,
                    'reporting_date'         => $reportDate,
                    'exposure_at_default'    => $eclData['ead'],
                    'probability_of_default' => $eclData['pd'],
                    'loss_given_default'     => $eclData['lgd'],
                    'calculated_ecl'         => $eclData['calculated_ecl'],
                ]);

                // Accumulate totals
                match ($eclData['stage']) {
                    1 => $stage1Sum = bcadd($stage1Sum, $eclData['calculated_ecl'], 2),
                    2 => $stage2Sum = bcadd($stage2Sum, $eclData['calculated_ecl'], 2),
                    3 => $stage3Sum = bcadd($stage3Sum, $eclData['calculated_ecl'], 2),
                };
            }

            $totalReserve = bcadd(bcadd($stage1Sum, $stage2Sum, 2), $stage3Sum, 2);

            // 3. Dispatch Outbox Event for regulatory reporting
            $prevEvent = OutboxEvent::query()->latest('id')->first();
            $prevHash = $prevEvent ? $prevEvent->current_event_hash : str_repeat('0', 64);

            $payload = [
                'reporting_date'     => $reportDate,
                'total_evaluated'    => $totalLoans,
                'stage_1_reserve'    => $stage1Sum,
                'stage_2_reserve'    => $stage2Sum,
                'stage_3_reserve'    => $stage3Sum,
                'total_ecl_reserve'  => $totalReserve,
                'evaluated_at'       => $now->toIso8601String(),
            ];

            $currentHash = hash('sha256', $prevHash . 'portfolio.ecl_calculated' . json_encode($payload));

            OutboxEvent::create([
                'aggregate_type'      => EclProvision::class,
                'aggregate_id'        => 0, // Portfolio-level aggregate
                'event_name'          => 'portfolio.ecl_calculated',
                'payload'             => $payload,
                'previous_event_hash' => $prevHash,
                'current_event_hash'  => $currentHash,
                'is_published'        => false,
                'retry_count'         => 0,
            ]);

            AuditTrail::create([
                'user_id'        => auth()->id(),
                'action'         => 'IFRS9_PORTFOLIO_ECL_EVALUATED',
                'auditable_type' => EclProvision::class,
                'auditable_id'   => 0,
                'old_values'     => null,
                'new_values'     => [
                    'reporting_date' => $reportDate,
                    'total_reserve'  => $totalReserve,
                ],
                'ip_address'     => request()->ip() ?? '127.0.0.1',
                'user_agent'     => request()->userAgent() ?? 'System/CLI',
            ]);

            Log::channel('daily')->info(sprintf(
                'IFRS 9 ECL Audit Complete (%s): %d loans evaluated. Total Reserve: KES %s (S1: %s, S2: %s, S3: %s)',
                $reportDate,
                $totalLoans,
                $totalReserve,
                $stage1Sum,
                $stage2Sum,
                $stage3Sum
            ));

            return [
                'reporting_date'        => $reportDate,
                'total_loans_evaluated' => $totalLoans,
                'stage_1_provisions'    => $stage1Sum,
                'stage_2_provisions'    => $stage2Sum,
                'stage_3_provisions'    => $stage3Sum,
                'total_ecl_reserve'     => $totalReserve,
            ];
        });
    }

    /**
     * Resolves statutory Probability of Default (PD) based on stage and risk rating.
     */
    private function resolveProbabilityOfDefault(int $stage, string $riskBand): string
    {
        // Stage 3 (Defaulted): Probability of default is mathematically 100%
        if ($stage === 3) {
            return '1.0000';
        }

        // Stage 2 (Underperforming / SICR): Lifetime elevated PD
        if ($stage === 2) {
            return match ($riskBand) {
                'A'     => '0.2500', // 25%
                'B'     => '0.3500', // 35%
                'C'     => '0.5000', // 50%
                default => '0.6500', // 65%
            };
        }

        // Stage 1 (Performing): Standard 12-Month PD
        return match ($riskBand) {
            'A'     => '0.0250', // 2.5%
            'B'     => '0.0500', // 5.0%
            'C'     => '0.1000', // 10.0%
            default => '0.1800', // 18.0%
        };
    }
}