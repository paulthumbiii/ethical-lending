<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanLedgerEntry;
use App\Models\LoanProduct;
use App\Services\Financial\PaymentWaterfallService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

function mlkLoan(): Loan
{
    $borrower = Borrower::create([
        'full_name'      => 'Lock Tester',
        'id_number'      => '31000001',
        'phone_number'   => '254733000001',
        'credit_limit'   => '50000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::create([
        'name'                        => 'Lock Test Product',
        'product_code'                => 'MLK-30',
        'min_principal'               => '1000.00',
        'max_principal'               => '50000.00',
        'default_tenure_days'         => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active'                   => true,
    ]);

    $application = LoanApplication::create([
        'application_number'    => 'APP-MLK-01',
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => $product->id,
        'requested_principal'   => '10000.00',
        'requested_tenure_days' => 30,
        'status'                => 'approved',
    ]);

    $loan = Loan::create([
        'loan_number'         => 'LN-MLK-01',
        'loan_application_id' => $application->id,
        'borrower_id'         => $borrower->id,
        'loan_product_id'     => $product->id,
        'principal_disbursed' => '10000.00',
        'annual_interest_rate'=> '18.00',
        'tenure_days'         => 30,
        'status'              => LoanStatus::DISBURSED,
        'disbursed_at'        => CarbonImmutable::now()->subDays(15),
        'due_date'            => CarbonImmutable::now()->addDays(15),
        'is_in_duplum_locked' => false,
    ]);

    LoanLedgerEntry::create([
        'loan_id'                   => $loan->id,
        'entry_type'                => 'accrual_baseline',
        'debit'                     => '12000.00',
        'credit'                    => '0.00',
        'running_principal_balance' => '10000.00',
        'running_interest_balance'  => '1500.00',
        'running_fees_balance'      => '500.00',
        'total_running_balance'     => '12000.00',
        'reference'                 => 'INIT-DEBT',
        'notes'                     => 'Baseline for lock test',
    ]);

    return $loan;
}

function mlkHoldRowLock(Loan $loan): void
{
    $holder = DB::connection('mysql_lock');
    $holder->beginTransaction();
    $holder->select(
        'select id from ' . $loan->getTable() . ' where id = ? for update',
        [$loan->id]
    );
}

function mlkEmptyTestDatabase(): void
{
    $database = (string) DB::connection()->getDatabaseName();

    if (! str_ends_with($database, '_test')) {
        throw new RuntimeException('Refusing to empty tables: database name must end in _test.');
    }

    $tables = DB::select(
        'select table_name as name from information_schema.tables where table_schema = ? and table_type = ?',
        [$database, 'BASE TABLE']
    );

    DB::statement('SET FOREIGN_KEY_CHECKS=0');

    foreach ($tables as $table) {
        if ($table->name === 'migrations') {
            continue;
        }

        DB::statement('TRUNCATE TABLE `' . $database . '`.`' . $table->name . '`');
    }

    DB::statement('SET FOREIGN_KEY_CHECKS=1');
}

beforeEach(function (): void {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Needs MySQL: run with --configuration=phpunit.mysql.xml');
    }

    if (! str_ends_with((string) DB::connection()->getDatabaseName(), '_test')) {
        throw new RuntimeException('Refusing to run: database name must end in _test.');
    }

    config([
        'database.connections.mysql_lock' => config('database.connections.' . config('database.default')),
    ]);

    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function (): void {
    if (DB::getDriverName() !== 'mysql') {
        return;
    }

    $holder = DB::connection('mysql_lock');
    if ($holder->transactionLevel() > 0) {
        $holder->rollBack();
    }
    DB::purge('mysql_lock');

    DB::statement('SET SESSION innodb_lock_wait_timeout = 50');

    mlkEmptyTestDatabase();
});

it('makes a payment wait while another connection holds the loan row', function (): void {
    $loan = mlkLoan();
    mlkHoldRowLock($loan);

    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

    $service = app(PaymentWaterfallService::class);

    expect(fn () => $service->executeWaterfall($loan, '1000.00', 'MLK-BLOCKED-01'))
        ->toThrow(QueryException::class, 'Lock wait timeout');

    expect(LoanLedgerEntry::where('loan_id', $loan->id)->count())->toBe(1);
    expect(DB::table('payment_waterfall_logs')->where('loan_id', $loan->id)->count())->toBe(0);
});

it('credits a payment exactly once after the lock is released', function (): void {
    $loan = mlkLoan();
    mlkHoldRowLock($loan);

    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

    $service = app(PaymentWaterfallService::class);

    expect(fn () => $service->executeWaterfall($loan, '1000.00', 'MLK-RETRY-01'))
        ->toThrow(QueryException::class, 'Lock wait timeout');

    DB::connection('mysql_lock')->rollBack();

    $result = $service->executeWaterfall($loan, '1000.00', 'MLK-RETRY-01');

    expect($result['allocated_fees'])->toBe('500.00')
        ->and($result['allocated_interest'])->toBe('500.00')
        ->and($result['allocated_principal'])->toBe('0.00')
        ->and($result['new_total_balance'])->toBe('11000.00');

    expect(LoanLedgerEntry::where('loan_id', $loan->id)->count())->toBe(2);
    expect(DB::table('payment_waterfall_logs')->where('loan_id', $loan->id)->count())->toBe(1);
});