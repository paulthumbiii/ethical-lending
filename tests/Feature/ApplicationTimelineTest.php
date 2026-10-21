<?php

declare(strict_types=1);

use App\Enums\StatementReviewStatus;
use App\Enums\UserRole;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\MpesaStatementUpload;
use App\Models\User;
use App\Services\Customer\ApplicationTimelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** @return array{0: User, 1: Borrower, 2: LoanProduct, 3: LoanApplication} */
function atlSetup(string $status = 'pending'): array
{
    Role::findOrCreate(UserRole::Borrower->value, 'web');

    $user = User::create([
        'name' => 'Timeline Tester',
        'email' => 'atl@example.test',
        'password' => 'Secret@12345',
        'phone_number' => '254711000001',
        'is_active' => true,
    ]);
    $user->assignRole(UserRole::Borrower->value);

    $borrower = Borrower::create([
        'user_id' => $user->id,
        'full_name' => 'Timeline Tester',
        'id_number' => '30000001',
        'phone_number' => '254711000001',
        'credit_limit' => '0.00',
        'risk_band' => 'C',
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::create([
        'name' => 'Timeline Product',
        'product_code' => 'ATL-30',
        'min_principal' => '1000.00',
        'max_principal' => '20000.00',
        'default_tenure_days' => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active' => true,
    ]);

    $application = LoanApplication::create([
        'application_number' => 'APP-ATL-' . strtoupper(Str::random(6)),
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'requested_principal' => '5000.00',
        'requested_tenure_days' => 30,
        'status' => $status,
    ]);

    return [$user, $borrower, $product, $application];
}

function atlStatement(LoanApplication $application, StatementReviewStatus $status, array $extra = []): MpesaStatementUpload
{
    return MpesaStatementUpload::create(array_merge([
        'borrower_id' => $application->borrower_id,
        'loan_application_id' => $application->id,
        'file_path' => 'statements/' . $application->borrower_id . '/' . Str::random(40) . '.pdf',
        'original_name' => 'statement.pdf',
        'file_size' => 1024,
        'file_sha256' => hash('sha256', Str::random(16)),
        'review_status' => $status,
    ], $extra));
}

/** @return array<string, string> step key => state */
function atlStates(LoanApplication $application): array
{
    return collect(app(ApplicationTimelineService::class)->build($application->fresh()))
        ->pluck('state', 'key')
        ->all();
}

it('shows a brand new application waiting for a statement', function () {
    [, , , $application] = atlSetup('pending');

    expect(atlStates($application))->toBe([
        'received' => 'done',
        'statement_received' => 'current',
        'statement_checked' => 'upcoming',
        'credit_check' => 'upcoming',
        'second_approval' => 'upcoming',
        'approved' => 'upcoming',
        'money_sent' => 'upcoming',
    ]);
});

it('shows a statement that is being checked', function () {
    [, , , $application] = atlSetup('pending');
    atlStatement($application, StatementReviewStatus::AwaitingReview);

    $states = atlStates($application);

    expect($states['statement_received'])->toBe('done')
        ->and($states['statement_checked'])->toBe('current')
        ->and($states['credit_check'])->toBe('upcoming');
});

it('flags a rejected statement and carries the reason', function () {
    [, , , $application] = atlSetup('pending');
    atlStatement($application, StatementReviewStatus::Rejected, ['review_notes' => 'Pages are missing.']);

    $steps = collect(app(ApplicationTimelineService::class)->build($application->fresh()))->keyBy('key');

    expect($steps['statement_checked']['state'])->toBe('problem')
        ->and($steps['statement_checked']['detail'])->toContain('Pages are missing.')
        ->and($steps['credit_check']['state'])->toBe('upcoming');
});

it('moves to the credit check once the statement is accepted', function () {
    [, , , $application] = atlSetup('pending');
    atlStatement($application, StatementReviewStatus::Accepted, ['valid_until' => now()->addDays(30)->toDateString()]);

    $states = atlStates($application);

    expect($states['statement_checked'])->toBe('done')
        ->and($states['credit_check'])->toBe('current')
        ->and($states['second_approval'])->toBe('upcoming');
});

it('waits for a second approver after the credit check passes', function () {
    [, , , $application] = atlSetup('approved_pending_checker');
    atlStatement($application, StatementReviewStatus::Accepted);

    $states = atlStates($application);

    expect($states['credit_check'])->toBe('done')
        ->and($states['second_approval'])->toBe('current')
        ->and($states['approved'])->toBe('upcoming')
        ->and($states['money_sent'])->toBe('upcoming');
});

it('waits for payout once approved', function () {
    [, , , $application] = atlSetup('approved');
    atlStatement($application, StatementReviewStatus::Accepted);

    $states = atlStates($application);

    expect($states['second_approval'])->toBe('done')
        ->and($states['approved'])->toBe('done')
        ->and($states['money_sent'])->toBe('current');
});

it('marks every step done once the money has been sent', function () {
    [, $borrower, $product, $application] = atlSetup('approved');
    atlStatement($application, StatementReviewStatus::Accepted);

    Loan::create([
        'loan_number' => 'LN-ATL-001',
        'loan_application_id' => $application->id,
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'principal_disbursed' => '5000.00',
        'annual_interest_rate' => '18.00',
        'tenure_days' => 30,
        'status' => 'disbursed',
        'disbursed_at' => now(),
        'due_date' => now()->addDays(30),
    ]);

    expect(array_unique(array_values(atlStates($application))))->toBe(['done']);
});

it('follows the newest upload after a rejection', function () {
    [, , , $application] = atlSetup('pending');
    atlStatement($application, StatementReviewStatus::Rejected, ['review_notes' => 'Blurry.']);
    atlStatement($application, StatementReviewStatus::AwaitingReview);

    expect(atlStates($application)['statement_checked'])->toBe('current');
});

it('shows the timeline to the borrower without any staff-only flags', function () {
    [$user, , , $application] = atlSetup('pending');
    atlStatement($application, StatementReviewStatus::Rejected, [
        'review_notes' => 'Please upload a clearer copy.',
        'flags' => ['same_file_other_borrower'],
    ]);

    $this->withoutVite();

    $this->actingAs($user)
        ->get(route('borrower.apply.create'))
        ->assertOk()
        ->assertSee('Where your application is')
        ->assertSee('Final approval by a second person')
        ->assertSee('Please upload a clearer copy.')
        ->assertDontSee('same_file_other_borrower');
});