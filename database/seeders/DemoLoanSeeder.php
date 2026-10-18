<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LoanStatus;
use App\Models\Borrower;
use App\Models\InDuplumTracking;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanLedgerEntry;
use App\Models\LoanProduct;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** LOCAL DEMO ONLY. Never run this against a live database. */
final class DemoLoanSeeder extends Seeder
{
    public function run(): void
    {
        $borrower = Borrower::query()->where('id_number', '12345678')->first();

        if ($borrower === null) {
            $this->command?->error('Run DemoUsersSeeder first: the demo borrower was not found.');

            return;
        }

        if (Loan::query()->where('loan_number', 'LN-DEMO-001')->exists()) {
            $this->command?->info('Demo loan already exists.');

            return;
        }

        DB::transaction(function () use ($borrower): void {
            $now = CarbonImmutable::now();

            $product = LoanProduct::firstOrCreate(['product_code' => 'BMC-30'], [
                'name'                        => 'Biashara Micro Capital',
                'min_principal'               => '1000.00',
                'max_principal'               => '50000.00',
                'default_tenure_days'         => 30,
                'interest_calculation_method' => 'reducing_balance',
                'is_active'                   => true,
            ]);

            $application = LoanApplication::create([
                'application_number'    => 'APP-DEMO-001',
                'borrower_id'          => $borrower->id,
                'loan_product_id'      => $product->id,
                'requested_principal'  => '20000.00',
                'requested_tenure_days' => 30,
                'status'               => 'approved',
            ]);

            $loan = Loan::create([
                'loan_number'         => 'LN-DEMO-001',
                'loan_application_id' => $application->id,
                'borrower_id'         => $borrower->id,
                'loan_product_id'     => $product->id,
                'principal_disbursed' => '20000.00',
                'annual_interest_rate' => '18.00',
                'tenure_days'         => 30,
                'status'              => LoanStatus::DEFAULTED,
                'disbursed_at'        => $now->subDays(130),
                'due_date'            => $now->subDays(100),
                'defaulted_at'        => $now->subDays(10),
                'is_in_duplum_locked' => false,
            ]);

            // Borrower paid KES 8,000 before default, so principal at default is KES 12,000.
            LoanLedgerEntry::create([
                'loan_id'                   => $loan->id,
                'entry_type'                => 'disbursement',
                'debit'                     => '20000.00',
                'credit'                    => '0.00',
                'running_principal_balance' => '20000.00',
                'running_interest_balance'  => '0.00',
                'running_fees_balance'      => '0.00',
                'total_running_balance'     => '20000.00',
                'reference'                 => 'DISB-DEMO-001',
            ]);

            LoanLedgerEntry::create([
                'loan_id'                   => $loan->id,
                'entry_type'                => 'repayment',
                'debit'                     => '0.00',
                'credit'                    => '8000.00',
                'running_principal_balance' => '12000.00',
                'running_interest_balance'  => '3200.00',
                'running_fees_balance'      => '1500.00',
                'total_running_balance'     => '16700.00',
                'reference'                 => 'PAY-DEMO-001',
            ]);

            InDuplumTracking::create([
                'loan_id'                      => $loan->id,
                'defaulted_at'                 => $now->subDays(10),
                'default_principal_snapshot'   => '12000.00',
                'total_post_default_interest'  => '3200.00',
                'total_post_default_penalties' => '1500.00',
                'statutory_cap_limit'          => '12000.00',
                'is_frozen'                    => false,
            ]);
        });

        $this->command?->info('Demo defaulted loan LN-DEMO-001 created for the demo borrower.');
    }
}