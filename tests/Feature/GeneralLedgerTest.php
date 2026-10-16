<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Exceptions\Fintech\AccountingPeriodClosedException;
use App\Exceptions\Fintech\UnbalancedJournalEntryException;
use App\Models\AccountingPeriod;
use App\Models\Borrower;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\TaxWithholdingLedger;
use App\Services\Financial\GeneralLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    // Seed standard core Chart of Accounts (COA)
    ChartOfAccount::create(['account_code' => '1010', 'account_name' => 'M-Pesa Settlement Account', 'account_type' => 'ASSET', 'currency' => 'KES', 'is_active' => true]);
    ChartOfAccount::create(['account_code' => '1200', 'account_name' => 'Loans Receivable', 'account_type' => 'ASSET', 'currency' => 'KES', 'is_active' => true]);
    ChartOfAccount::create(['account_code' => '2010', 'account_name' => 'Customer Overpayment Credits', 'account_type' => 'LIABILITY', 'currency' => 'KES', 'is_active' => true]);
    ChartOfAccount::create(['account_code' => '2030', 'account_name' => 'KRA Excise Duty Payable', 'account_type' => 'LIABILITY', 'currency' => 'KES', 'is_active' => true]);
    ChartOfAccount::create(['account_code' => '4010', 'account_name' => 'Interest Income', 'account_type' => 'REVENUE', 'currency' => 'KES', 'is_active' => true]);
    ChartOfAccount::create(['account_code' => '4020', 'account_name' => 'Fee & Penalty Income', 'account_type' => 'REVENUE', 'currency' => 'KES', 'is_active' => true]);

    $this->borrower = Borrower::create([
        'full_name'      => 'Wanjiku Kamau',
        'id_number'      => '28471928',
        'phone_number'   => '254712345678',
        'credit_limit'   => '50000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);

    $this->product = LoanProduct::create([
        'name'                        => 'Biashara Micro Capital',
        'product_code'                => 'BMC-30',
        'min_principal'               => '1000.00',
        'max_principal'               => '50000.00',
        'default_tenure_days'         => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active'                   => true,
    ]);

    $this->application = LoanApplication::create([
        'application_number'   => 'APP-GL-01',
        'borrower_id'          => $this->borrower->id,
        'loan_product_id'      => $this->product->id,
        'requested_principal'  => '20000.00',
        'requested_tenure_days'=> 30,
        'status'               => 'approved',
    ]);

    $this->loan = Loan::create([
        'loan_number'         => 'LN-GL-01',
        'loan_application_id' => $this->application->id,
        'borrower_id'         => $this->borrower->id,
        'loan_product_id'     => $this->product->id,
        'principal_disbursed' => '20000.00',
        'annual_interest_rate'=> '15.00',
        'tenure_days'         => 30,
        'status'              => LoanStatus::DISBURSED,
        'disbursed_at'        => CarbonImmutable::now(),
        'due_date'            => CarbonImmutable::now()->addDays(30),
        'is_in_duplum_locked' => false,
    ]);

    $this->glService = app(GeneralLedgerService::class);
});

it('posts balanced double-entry transactions successfully', function (): void {
    $entry = $this->glService->postBalancedEntry(
        description: 'Test balanced manual posting',
        items: [
            ['account_code' => '1010', 'debit' => '5000.00', 'credit' => '0.00'],
            ['account_code' => '4010', 'debit' => '0.00', 'credit' => '5000.00'],
        ]
    );

    expect($entry)->toBeInstanceOf(JournalEntry::class)
        ->and($entry->items)->toHaveCount(2);

    $totalDebits = $entry->items->sum('debit');
    $totalCredits = $entry->items->sum('credit');

    expect(number_format((float) $totalDebits, 2, '.', ''))->toBe('5000.00')
        ->and(number_format((float) $totalCredits, 2, '.', ''))->toBe('5000.00');
});

it('throws UnbalancedJournalEntryException when debits and credits do not match', function (): void {
    $this->glService->postBalancedEntry(
        description: 'Unbalanced posting attempt',
        items: [
            ['account_code' => '1010', 'debit' => '5000.00', 'credit' => '0.00'],
            ['account_code' => '4010', 'debit' => '0.00', 'credit' => '4999.00'], // Off by KES 1.00!
        ]
    );
})->throws(UnbalancedJournalEntryException::class);

it('throws AccountingPeriodClosedException when attempting to post to a locked period', function (): void {
    $now = CarbonImmutable::now();
    AccountingPeriod::create([
        'period_name' => $now->format('F Y'),
        'start_date'  => $now->startOfMonth()->toDateString(),
        'end_date'    => $now->endOfMonth()->toDateString(),
        'is_locked'   => true, // Period closed!
    ]);

    $this->glService->postBalancedEntry(
        description: 'Posting to closed period',
        items: [
            ['account_code' => '1010', 'debit' => '1000.00', 'credit' => '0.00'],
            ['account_code' => '4010', 'debit' => '0.00', 'credit' => '1000.00'],
        ]
    );
})->throws(AccountingPeriodClosedException::class);

it('accurately splits processing fees into 80% revenue and 20% KRA excise duty', function (): void {
    // KES 1,000 processing fee charged to borrower
    $entry = $this->glService->recordFeeWithExcisePosting($this->loan, 'Loan Processing Fee', '1000.00');

    expect($entry->items)->toHaveCount(3);

    // Verify 20% Excise Duty ledger record
    $taxRecord = TaxWithholdingLedger::where('loan_id', $this->loan->id)->first();
    expect($taxRecord)->not->toBeNull()
        ->and(number_format((float) $taxRecord->excise_duty_amount, 2, '.', ''))->toBe('200.00')
        ->and($taxRecord->remittance_status)->toBe('accrued');

    // Verify balanced lines:
    // Debit 1200: 1000.00 | Credit 4020: 800.00 | Credit 2030: 200.00
    $debitTotal = $entry->items->sum('debit');
    $creditTotal = $entry->items->sum('credit');

    expect(number_format((float) $debitTotal, 2, '.', ''))->toBe('1000.00')
        ->and(number_format((float) $creditTotal, 2, '.', ''))->toBe('1000.00');
});

it('accurately posts loan disbursement and repayment across double-entry accounts', function (): void {
    // 1. Disbursement: KES 20,000
    $disbEntry = $this->glService->recordLoanDisbursementPosting($this->loan, '20000.00');
    expect(number_format((float) $disbEntry->items->sum('debit'), 2, '.', ''))->toBe('20000.00');

    // 2. Repayment with overpayment: KES 21,500
    // Split: 500 fee, 1,000 interest, 19,000 principal, 1,000 overpayment credit
    $repayEntry = $this->glService->recordRepaymentPosting(
        loan: $this->loan,
        feesPaid: '500.00',
        interestPaid: '1000.00',
        principalPaid: '19000.00',
        overpayment: '1000.00',
        reference: 'MPESA-REPAY-GL-01'
    );

    expect($repayEntry->items)->toHaveCount(5);

    $repayDebits = $repayEntry->items->sum('debit');
    $repayCredits = $repayEntry->items->sum('credit');

    // Total cash in = 21,500.00
    expect(number_format((float) $repayDebits, 2, '.', ''))->toBe('21500.00')
        ->and(number_format((float) $repayCredits, 2, '.', ''))->toBe('21500.00');
});