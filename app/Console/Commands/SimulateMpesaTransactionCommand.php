<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Loan;
use App\Models\LoanLedgerEntry;
use App\Services\Financial\Mpesa\MpesaSimulationHarness;
use Illuminate\Console\Command;

final class SimulateMpesaTransactionCommand extends Command
{
    protected $signature = 'mpesa:simulate 
                            {--type=c2b : Payment type to simulate: "c2b" or "stk"}
                            {--loan= : Loan ID or Loan Number (defaults to latest active loan)}
                            {--amount=1000.00 : Payment amount in KES}
                            {--fail : Simulate user cancellation on phone}';

    protected $description = 'Simulates incoming Safaricom M-Pesa C2B and STK repayments without calling live APIs';

    public function handle(MpesaSimulationHarness $harness): int
    {
        $this->info('Initializing Safaricom M-Pesa Transaction Simulator...');

        // 1. Resolve Target Loan
        $loanIdentifier = $this->option('loan');
        $query = Loan::query()->with('borrower');

        if ($loanIdentifier !== null) {
            $query->where('id', $loanIdentifier)->orWhere('loan_number', $loanIdentifier);
        } else {
            $query->whereIn('status', ['disbursed', 'defaulted', 'watch'])->latest('id');
        }

        $loan = $query->first();

        if ($loan === null) {
            $this->error('No active loan found to simulate repayment against. Please seed or disburse a loan first.');
            return self::FAILURE;
        }

        $amount = (string) $this->option('amount');
        $type = strtolower((string) $this->option('type'));

        $this->line(sprintf(
            'Target: Loan #<fg=yellow;options=bold>%s</> (Borrower: %s | Phone: %s)',
            $loan->loan_number,
            $loan->borrower->full_name ?? 'N/A',
            $loan->borrower->phone_number ?? 'N/A'
        ));

        // 2. Execute Simulation
        if ($type === 'c2b') {
            $result = $harness->simulateC2bPayment($loan, $amount);

            $this->info('<fg=green;options=bold>SUCCESS:</> C2B Paybill payment simulated successfully!');
            $this->table(
                ['Parameter', 'Value'],
                [
                    ['Channel', 'M-Pesa Paybill (C2B)'],
                    ['M-Pesa Receipt', $result['trans_id']],
                    ['Amount Received', 'KES ' . number_format((float) $amount, 2)],
                    ['Account / BillRef', $loan->loan_number],
                ]
            );
        } elseif ($type === 'stk') {
            if ($this->option('fail')) {
                $harness->simulateStkCancelled($loan, $amount);
                $this->warn('STK Push Cancelled simulation executed: ResultCode 1032 recorded.');
                return self::SUCCESS;
            }

            $result = $harness->simulateStkSuccess($loan, $amount);

            $this->info('<fg=green;options=bold>SUCCESS:</> STK Push repayment simulated successfully!');
            $this->table(
                ['Parameter', 'Value'],
                [
                    ['Channel', 'Lipa na M-Pesa Online (STK)'],
                    ['M-Pesa Receipt', $result['receipt_number']],
                    ['Checkout Request ID', $result['checkout_request_id']],
                    ['Amount Received', 'KES ' . number_format((float) $amount, 2)],
                ]
            );
        } else {
            $this->error('Invalid type option. Supported types are "c2b" or "stk".');
            return self::FAILURE;
        }

        // 3. Display Updated Balance from Ledger
        $latest = LoanLedgerEntry::where('loan_id', $loan->id)->latest('id')->first();
        if ($latest !== null) {
            $this->line(sprintf(
                'Updated Loan Balance: <fg=green;options=bold>KES %s</> (Principal: %s | Interest: %s | Fees: %s)',
                number_format((float) $latest->total_running_balance, 2),
                number_format((float) $latest->running_principal_balance, 2),
                number_format((float) $latest->running_interest_balance, 2),
                number_format((float) $latest->running_fees_balance, 2)
            ));
        }

        return self::SUCCESS;
    }
}