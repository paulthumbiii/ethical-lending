<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Enums\UserRole;
use App\Models\Borrower;
use App\Models\InDuplumTracking;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanLedgerEntry;
use App\Models\LoanProduct;
use App\Models\MpesaStkRequest;
use App\Models\RestructuringProposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function bpProduct(): LoanProduct
{
    return LoanProduct::firstOrCreate(['product_code' => 'BMC-30'], [
        'name'                        => 'Biashara Micro Capital',
        'min_principal'               => '1000.00',
        'max_principal'               => '50000.00',
        'default_tenure_days'         => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active'                   => true,
    ]);
}

function bpUser(string $n): User
{
    $user = User::create([
        'name'         => "Borrower User {$n}",
        'email'        => "borrower{$n}@example.test",
        'password'     => 'secret-pass-1',
        'phone_number' => '2547330' . str_pad($n, 5, '0', STR_PAD_LEFT),
        'is_active'    => true,
    ]);
    $user->assignRole(UserRole::Borrower->value);

    return $user;
}

function bpBorrower(string $n, ?User $user = null): Borrower
{
    return Borrower::create([
        'user_id'        => $user?->id,
        'full_name'      => "Borrower {$n}",
        'id_number'      => '2847' . str_pad($n, 4, '0', STR_PAD_LEFT),
        'phone_number'   => '2547220' . str_pad($n, 5, '0', STR_PAD_LEFT),
        'credit_limit'   => '50000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);
}

function bpLoan(Borrower $borrower, string $n, array $overrides = []): Loan
{
    $product = bpProduct();

    $application = LoanApplication::create([
        'application_number'    => "APP-BP-{$n}",
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => $product->id,
        'requested_principal'   => '20000.00',
        'requested_tenure_days' => 30,
        'status'                => 'approved',
    ]);

    return Loan::create(array_merge([
        'loan_number'          => "LN-BP-{$n}",
        'loan_application_id'  => $application->id,
        'borrower_id'          => $borrower->id,
        'loan_product_id'      => $product->id,
        'principal_disbursed'  => '20000.00',
        'annual_interest_rate' => '18.00',
        'tenure_days'          => 30,
        'status'               => LoanStatus::DEFAULTED,
        'disbursed_at'         => CarbonImmutable::now()->subDays(130),
        'due_date'             => CarbonImmutable::now()->subDays(100),
        'is_in_duplum_locked'  => false,
    ], $overrides));
}

/** Disbursement, then KES 8,000 repaid; leaves 12,000 principal + 3,200 interest + 1,500 fees = 16,700 owed. */
function bpHistory(Loan $loan): void
{
    LoanLedgerEntry::create([
        'loan_id'                   => $loan->id,
        'entry_type'                => 'disbursement',
        'debit'                     => '20000.00',
        'credit'                    => '0.00',
        'running_principal_balance' => '20000.00',
        'running_interest_balance'  => '0.00',
        'running_fees_balance'      => '0.00',
        'total_running_balance'     => '20000.00',
        'reference'                 => 'DISB-' . $loan->loan_number,
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
        'reference'                 => 'PAY-' . $loan->loan_number,
    ]);

    InDuplumTracking::create([
        'loan_id'                      => $loan->id,
        'defaulted_at'                 => CarbonImmutable::now()->subDays(10),
        'default_principal_snapshot'   => '12000.00',
        'total_post_default_interest'  => '3200.00',
        'total_post_default_penalties' => '1500.00',
        'statutory_cap_limit'          => '12000.00',
        'is_frozen'                    => false,
    ]);
}

function bpFakeDaraja(?array $stkResponse = null, int $status = 200): void
{
    Http::fake([
        '*/oauth/v1/generate*'               => Http::response(['access_token' => 'test-token', 'expires_in' => '3599']),
        '*/mpesa/stkpush/v1/processrequest' => Http::response($stkResponse ?? [
            'MerchantRequestID'   => 'MR-TEST-1',
            'CheckoutRequestID'   => 'ws_CO_TEST_1',
            'ResponseCode'        => '0',
            'ResponseDescription' => 'Success. Request accepted for processing',
            'CustomerMessage'     => 'Success. Request accepted for processing',
        ], $status),
    ]);
}

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(RolesSeeder::class);

    config(['mpesa.stk_callback_url' => 'https://example.test/api/v1/mpesa/stk-callback']);

    $this->user = bpUser('1');
    $this->borrower = bpBorrower('1', $this->user);
    $this->loan = bpLoan($this->borrower, '001');
    bpHistory($this->loan);
});

it('shows the borrower their balance, legal limit meter, relief offer and payment box', function (): void {
    $this->actingAs($this->user)->get(route('borrower.dashboard'))
        ->assertOk()
        ->assertSee('LN-BP-001')
        ->assertSee('KES 16,700.00')
        ->assertSee('Your legal limit on charges')
        ->assertSee('KES 4,700.00')
        ->assertSee('Counting')
        ->assertSee('Finding it hard to pay?')
        ->assertSee('Accept relief plan')
        ->assertSee('Pay with M-Pesa');
});

it('tells a borrower with no loan that nothing is active', function (): void {
    $user = bpUser('2');
    bpBorrower('2', $user);

    $this->actingAs($user)->get(route('borrower.dashboard'))
        ->assertOk()
        ->assertSee('no active loan')
        ->assertDontSee('Pay with M-Pesa');
});

it('shows charges stopped once the cap is reached', function (): void {
    InDuplumTracking::query()->where('loan_id', $this->loan->id)->update([
        'total_post_default_interest'  => '12000.00',
        'total_post_default_penalties' => '0.00',
        'is_frozen'                    => true,
    ]);
    $this->loan->update(['is_in_duplum_locked' => true]);

    $this->actingAs($this->user)->get(route('borrower.dashboard'))
        ->assertOk()
        ->assertSee('Charges stopped');
});

it('accepts a relief plan through the restructuring engine', function (): void {
    $this->actingAs($this->user)
        ->post(route('borrower.restructuring.accept', $this->loan), ['tenure_extension_days' => 30])
        ->assertRedirect(route('borrower.dashboard'))
        ->assertSessionHas('status');

    expect($this->loan->refresh()->status)->toBe(LoanStatus::RESTRUCTURED)
        ->and(RestructuringProposal::query()->where('loan_id', $this->loan->id)->value('status'))->toBe('accepted');
});

it('refuses relief when the repayment history is too weak and changes nothing', function (): void {
    $user = bpUser('3');
    $borrower = bpBorrower('3', $user);
    $loan = bpLoan($borrower, '003');

    $this->actingAs($user)
        ->post(route('borrower.restructuring.accept', $loan), ['tenure_extension_days' => 30])
        ->assertSessionHasErrors('relief');

    expect($loan->refresh()->status)->toBe(LoanStatus::DEFAULTED)
        ->and(RestructuringProposal::query()->where('loan_id', $loan->id)->count())->toBe(0);
});

it('rejects a time option that is not offered', function (): void {
    $this->actingAs($this->user)
        ->post(route('borrower.restructuring.accept', $this->loan), ['tenure_extension_days' => 999])
        ->assertSessionHasErrors('tenure_extension_days');

    expect($this->loan->refresh()->status)->toBe(LoanStatus::DEFAULTED);
});

it('returns 404 when a borrower tries to act on someone else\'s loan', function (): void {
    $otherLoan = bpLoan(bpBorrower('9'), '009');
    bpFakeDaraja();

    $this->actingAs($this->user)
        ->post(route('borrower.restructuring.accept', $otherLoan), ['tenure_extension_days' => 30])
        ->assertNotFound();

    $this->actingAs($this->user)
        ->post(route('borrower.payment.store', $otherLoan), ['amount' => 500])
        ->assertNotFound();

    Http::assertNothingSent();
});

it('sends an STK Push to the registered phone only and records it as pending', function (): void {
    bpFakeDaraja();

    $this->actingAs($this->user)
        ->post(route('borrower.payment.store', $this->loan), ['amount' => 500, 'phone_number' => '254799999999'])
        ->assertRedirect(route('borrower.dashboard'))
        ->assertSessionHas('status');

    $stk = MpesaStkRequest::query()->where('loan_id', $this->loan->id)->firstOrFail();

    expect($stk->checkout_request_id)->toBe('ws_CO_TEST_1')
        ->and($stk->phone_number)->toBe($this->borrower->phone_number)
        ->and($stk->status)->toBe('pending')
        ->and(bccomp((string) $stk->amount, '500.00', 2))->toBe(0);

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/mpesa/stkpush/v1/processrequest')) {
            return false;
        }

        $data = $request->data();

        return $data['PhoneNumber'] === $this->borrower->phone_number
            && $data['PartyA'] === $this->borrower->phone_number
            && $data['Amount'] === 500
            && $data['AccountReference'] === 'LN-BP-001'
            && $data['CallBackURL'] === 'https://example.test/api/v1/mpesa/stk-callback';
    });
});

it('refuses to prompt for more than the borrower owes', function (): void {
    bpFakeDaraja();

    $this->actingAs($this->user)
        ->post(route('borrower.payment.store', $this->loan), ['amount' => 20000])
        ->assertSessionHasErrors('payment');

    expect(MpesaStkRequest::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('shows a friendly error and records nothing when Safaricom rejects the prompt', function (): void {
    bpFakeDaraja(['errorMessage' => 'Bad Request'], 500);

    $this->actingAs($this->user)
        ->post(route('borrower.payment.store', $this->loan), ['amount' => 500])
        ->assertSessionHasErrors('payment');

    expect(MpesaStkRequest::query()->count())->toBe(0);
});

it('blocks a second prompt sent straight after the first', function (): void {
    bpFakeDaraja();

    $this->actingAs($this->user)->post(route('borrower.payment.store', $this->loan), ['amount' => 500]);
    $this->actingAs($this->user)
        ->post(route('borrower.payment.store', $this->loan), ['amount' => 500])
        ->assertSessionHasErrors('payment');

    expect(MpesaStkRequest::query()->count())->toBe(1);
});

it('explains clearly when payment prompts are not switched on', function (): void {
    config(['mpesa.stk_callback_url' => '']);
    bpFakeDaraja();

    $this->actingAs($this->user)
        ->post(route('borrower.payment.store', $this->loan), ['amount' => 500])
        ->assertSessionHasErrors('payment');

    expect(MpesaStkRequest::query()->count())->toBe(0);
});

it('validates the payment amount', function (): void {
    bpFakeDaraja();

    $this->actingAs($this->user)
        ->post(route('borrower.payment.store', $this->loan), ['amount' => 0])
        ->assertSessionHasErrors('amount');

    Http::assertNothingSent();
});