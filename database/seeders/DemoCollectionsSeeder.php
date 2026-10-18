<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Models\Borrower;
use App\Models\CollectionCase;
use App\Models\InDuplumTracking;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\PromiseToPay;
use App\Models\RegulatoryCooldownLock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * LOCAL DEMO DATA ONLY. Never run this on a live database.
 */
final class DemoCollectionsSeeder extends Seeder
{
    public function run(): void
    {
        $now = CarbonImmutable::now();

        // Case 1: the existing demo loan, inside the 48-hour quiet period.
        $demoLoan = Loan::query()->where('loan_number', 'LN-DEMO-001')->first();

        if ($demoLoan === null) {
            $this->command?->warn('LN-DEMO-001 not found. Run DemoLoanSeeder first.');
        } else {
            $case = $this->makeCase($demoLoan, 'DPD_31_60', $now->subHours(28));

            RegulatoryCooldownLock::updateOrCreate(
                ['borrower_id' => $demoLoan->borrower_id, 'lock_type' => 'COLLECTIONS_SMS'],
                [
                    'locked_until' => $now->addHours(20),
                    'reason'       => '48-hour quiet period following DPD_31_60 reminder on Loan #LN-DEMO-001',
                ]
            );

            $this->command?->info("Case #{$case->id}: LN-DEMO-001 in a quiet period.");
        }

        $product = LoanProduct::firstOrCreate(
            ['product_code' => 'DEMO-30'],
            [
                'name'                        => 'Demo Micro Loan',
                'min_principal'               => '1000.00',
                'max_principal'               => '50000.00',
                'default_tenure_days'         => 30,
                'interest_calculation_method' => 'reducing_balance',
                'is_active'                   => true,
            ]
        );

        // Case 2: over 90 days, frozen at the in-duplum cap.
        $baraka = $this->makeBorrower('Baraka Mwangi', '30009002', '254711000902');
        $frozenLoan = $this->makeLoan($baraka, $product, 'LN-DEMO-002', $now->subDays(130), $now->subDays(100), true);

        InDuplumTracking::firstOrCreate(
            ['loan_id' => $frozenLoan->id],
            [
                'defaulted_at'                 => $now->subDays(100),
                'default_principal_snapshot'   => '12000.00',
                'total_post_default_interest'  => '8000.00',
                'total_post_default_penalties' => '4000.00',
                'statutory_cap_limit'          => '12000.00',
                'is_frozen'                    => true,
                'frozen_at'                    => $now->subDays(20),
            ]
        );

        $this->makeCase($frozenLoan, 'DPD_90_PLUS', null);

        // Case 3: early arrears with a Promise-to-Pay.
        $amani = $this->makeBorrower('Amani Otieno', '30009003', '254711000903');
        $promiseLoan = $this->makeLoan($amani, $product, 'LN-DEMO-003', $now->subDays(50), $now->subDays(15), false);
        $promiseCase = $this->makeCase($promiseLoan, 'DPD_1_30', null);

        PromiseToPay::updateOrCreate(
            ['collection_case_id' => $promiseCase->id, 'status' => 'pending'],
            [
                'promised_date'   => $now->addDays(3)->toDateString(),
                'promised_amount' => '2500.00',
            ]
        );

        $this->command?->info('Demo collection cases ready.');
    }

    private function makeBorrower(string $name, string $idNumber, string $phone): Borrower
    {
        return Borrower::firstOrCreate(
            ['id_number' => $idNumber],
            [
                'full_name'      => $name,
                'phone_number'   => $phone,
                'credit_limit'   => '15000.00',
                'risk_band'      => RiskBand::B,
                'is_blacklisted' => false,
            ]
        );
    }

    private function makeLoan(
        Borrower $borrower,
        LoanProduct $product,
        string $loanNumber,
        CarbonImmutable $disbursedAt,
        CarbonImmutable $dueDate,
        bool $locked
    ): Loan {
        $application = LoanApplication::firstOrCreate(
            ['application_number' => 'APP-' . $loanNumber],
            [
                'borrower_id'           => $borrower->id,
                'loan_product_id'       => $product->id,
                'requested_principal'   => '12000.00',
                'requested_tenure_days' => 30,
                'status'                => 'approved',
            ]
        );

        return Loan::firstOrCreate(
            ['loan_number' => $loanNumber],
            [
                'loan_application_id'  => $application->id,
                'borrower_id'          => $borrower->id,
                'loan_product_id'      => $product->id,
                'principal_disbursed'  => '12000.00',
                'annual_interest_rate' => '18.00',
                'tenure_days'          => 30,
                'status'               => LoanStatus::DEFAULTED,
                'disbursed_at'         => $disbursedAt,
                'due_date'             => $dueDate,
                'defaulted_at'         => $dueDate,
                'is_in_duplum_locked'  => $locked,
            ]
        );
    }

    private function makeCase(Loan $loan, string $bucket, ?CarbonImmutable $lastContacted): CollectionCase
    {
        return CollectionCase::firstOrCreate(
            ['loan_id' => $loan->id],
            [
                'borrower_id'            => $loan->borrower_id,
                'dpd_bucket'             => $bucket,
                'assigned_agent_user_id' => null,
                'case_status'            => 'open',
                'last_contacted_at'      => $lastContacted,
            ]
        );
    }
}