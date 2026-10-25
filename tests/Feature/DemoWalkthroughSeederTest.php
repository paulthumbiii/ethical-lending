<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanLedgerEntry;
use Database\Seeders\DemoWalkthroughSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('creates a paid-out loan with the fee added to the balance', function () {
    $this->seed(DemoWalkthroughSeeder::class);

    $application = LoanApplication::where('application_number', 'APP-DEMO-W01')->firstOrFail();
    $loan = Loan::where('loan_application_id', $application->id)->firstOrFail();

    expect($loan->status)->toBe(LoanStatus::DISBURSED)
        ->and($loan->disbursed_at)->not->toBeNull();

    $latest = LoanLedgerEntry::where('loan_id', $loan->id)->latest('id')->firstOrFail();

    expect($latest->entry_type)->toBe('FEE_CHARGED')
        ->and($latest->running_principal_balance)->toBe('3000.00')
        ->and($latest->running_fees_balance)->toBe('150.00')
        ->and($latest->total_running_balance)->toBe('3150.00');

    expect(JournalEntry::count())->toBe(2);
});

it('leaves one application approved for less than asked and waiting for payout', function () {
    $this->seed(DemoWalkthroughSeeder::class);

    $application = LoanApplication::where('application_number', 'APP-DEMO-W02')->firstOrFail();

    expect($application->status)->toBe('approved')
        ->and(Loan::where('loan_application_id', $application->id)->exists())->toBeFalse()
        ->and(bccomp((string) $application->borrower->credit_limit, '1125.00', 2))->toBe(0);
});

it('can be run twice without duplicating anything', function () {
    $this->seed(DemoWalkthroughSeeder::class);
    $this->seed(DemoWalkthroughSeeder::class);

    expect(LoanApplication::whereIn('application_number', ['APP-DEMO-W01', 'APP-DEMO-W02'])->count())->toBe(2)
        ->and(Loan::count())->toBe(1);
});