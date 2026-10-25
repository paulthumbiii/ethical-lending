<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\StatementReviewStatus;
use App\Enums\UserRole;
use App\Models\Borrower;
use App\Models\BorrowerFinancialProfile;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\MpesaB2cDisbursement;
use App\Models\MpesaStatementUpload;
use App\Models\User;
use App\Services\Financial\Mpesa\B2cDisbursementService;
use App\Services\Lending\LoanOriginationService;
use Illuminate\Database\Seeder;

/**
 * Phase 11.5d. LOCAL DEMO ONLY. Never run this against a live database.
 * Story 1: a loan that went through the real origination, payout-callback and fee code.
 * Story 2: an approved application, approved for less than asked, waiting for "Send money".
 */
final class DemoWalkthroughSeeder extends Seeder
{
    private const PASSWORD = 'Demo@12345';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->error('The demo walkthrough only runs in local or testing environments.');

            return;
        }

        $this->call(DemoUsersSeeder::class);
        $this->call(LoanProductSeeder::class);
        $this->call(ChartOfAccountSeeder::class);

        $product = LoanProduct::query()->where('product_code', 'ETH-QUICK30')->firstOrFail();

        $this->finishedLoan($product);
        $this->readyToSend($product);

        $this->command?->info('Demo walkthrough ready. Log in with password ' . self::PASSWORD);
        $this->command?->info('Borrower with a paid-out loan: njeri@ethical-lending.test');
        $this->command?->info('Borrower waiting for payout:   otieno@ethical-lending.test');
        $this->command?->info('Staff: admin@, credit@, finance@ (all @ethical-lending.test)');
    }

    private function finishedLoan(LoanProduct $product): void
    {
        if (LoanApplication::query()->where('application_number', 'APP-DEMO-W01')->exists()) {
            $this->command?->info('Story 1 already exists, skipped.');

            return;
        }

        $borrower = $this->borrower('Njeri Mwangi', 'njeri@ethical-lending.test', '254700000020', '23456789', '3000.00');
        $application = $this->approvedApplication($borrower, $product, 'APP-DEMO-W01', '3000.00');

        $admin = User::query()->where('email', 'admin@ethical-lending.test')->firstOrFail();

        $loan = app(LoanOriginationService::class)->originate($application->id, $admin->id, '127.0.0.1', 'DemoWalkthroughSeeder');

        MpesaB2cDisbursement::create([
            'loan_id'                    => $loan->id,
            'originator_conversation_id' => 'SIM-DEMO-W01',
            'conversation_id'            => 'SIM-CONV-W01',
            'transaction_id'             => null,
            'recipient_phone'            => $borrower->phone_number,
            'amount'                     => $loan->principal_disbursed,
            'status'                     => 'initiated',
            'response_code'              => '0',
            'response_description'       => 'Simulated by the demo seeder',
            'disbursed_at'               => null,
        ]);

        app(B2cDisbursementService::class)->processCallback([
            'Result' => [
                'OriginatorConversationID' => 'SIM-DEMO-W01',
                'ConversationID'           => 'SIM-CONV-W01',
                'ResultCode'               => 0,
                'ResultDesc'               => 'Simulated success (demo seeder)',
                'TransactionID'            => 'DEMOW01RCPT',
            ],
        ]);
    }

    private function readyToSend(LoanProduct $product): void
    {
        if (LoanApplication::query()->where('application_number', 'APP-DEMO-W02')->exists()) {
            $this->command?->info('Story 2 already exists, skipped.');

            return;
        }

        // Asked for 1,500 but the credit limit is 1,125 (band C, 75%): the borrower sees the approved amount.
        $borrower = $this->borrower('Otieno Barasa', 'otieno@ethical-lending.test', '254700000021', '34567890', '1125.00');
        $this->approvedApplication($borrower, $product, 'APP-DEMO-W02', '1500.00');
    }

    private function borrower(string $name, string $email, string $phone, string $idNumber, string $limit): Borrower
    {
        $user = User::updateOrCreate(['email' => $email], [
            'name' => $name,
            'password' => self::PASSWORD,
            'phone_number' => $phone,
            'is_active' => true,
        ]);
        $user->syncRoles([UserRole::Borrower->value]);

        $borrower = Borrower::updateOrCreate(['id_number' => $idNumber], [
            'user_id' => $user->id,
            'full_name' => $name,
            'phone_number' => $phone,
            'email' => $email,
            'credit_limit' => $limit,
            'risk_band' => 'C',
            'is_blacklisted' => false,
        ]);

        BorrowerFinancialProfile::updateOrCreate(['borrower_id' => $borrower->id], [
            'monthly_income' => '60000.00',
            'monthly_expenses' => '25000.00',
            'mpesa_average_monthly_inflow' => '80000.00',
            'existing_debt_obligations' => '0.00',
        ]);

        return $borrower;
    }

    private function approvedApplication(Borrower $borrower, LoanProduct $product, string $number, string $requested): LoanApplication
    {
        $application = LoanApplication::create([
            'application_number' => $number,
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'requested_principal' => $requested,
            'requested_tenure_days' => (int) $product->default_tenure_days,
            'status' => 'approved',
        ]);

        MpesaStatementUpload::create([
            'borrower_id' => $borrower->id,
            'loan_application_id' => $application->id,
            'file_path' => 'statements/demo-' . $number . '.pdf',
            'review_status' => StatementReviewStatus::Accepted->value,
            'valid_until' => now()->addDays(30)->toDateString(),
        ]);

        return $application;
    }
}