<?php

declare(strict_types=1);

use App\Enums\LoanStatus;
use App\Enums\RiskBand;
use App\Enums\StatementReviewStatus;
use App\Enums\VerificationStatus;
use App\Models\Borrower;
use App\Models\BorrowerKycDocument;
use App\Models\BorrowerNotification;
use App\Models\ChartOfAccount;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\MpesaB2cDisbursement;
use App\Models\MpesaStatementUpload;
use App\Models\User;
use App\Services\Customer\KycDocumentService;
use App\Services\Financial\Mpesa\B2cDisbursementService;
use App\Services\Governance\MakerCheckerService;
use App\Services\Underwriting\StatementReviewService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(RolesSeeder::class);
});

function bntUser(string $n, string $role = 'borrower'): User
{
    $user = User::create([
        'name'         => 'Bnt User ' . $n,
        'email'        => 'bnt' . $n . '@bnt.test',
        'password'     => 'Demo@12345',
        'phone_number' => '254788' . str_pad($n, 6, '0', STR_PAD_LEFT),
        'is_active'    => true,
    ]);
    $user->assignRole($role);

    return $user;
}

function bntBorrower(string $n, ?User $user = null): Borrower
{
    return Borrower::create([
        'user_id'        => $user?->id,
        'full_name'      => 'Bnt Borrower ' . $n,
        'id_number'      => '7391' . str_pad($n, 4, '0', STR_PAD_LEFT),
        'phone_number'   => '254799' . str_pad($n, 6, '0', STR_PAD_LEFT),
        'credit_limit'   => '20000.00',
        'risk_band'      => RiskBand::B,
        'is_blacklisted' => false,
    ]);
}

function bntProduct(): LoanProduct
{
    return LoanProduct::firstOrCreate(['product_code' => 'BNT-30'], [
        'name'                        => 'Bnt Product',
        'min_principal'               => '1000.00',
        'max_principal'               => '50000.00',
        'default_tenure_days'         => 30,
        'interest_calculation_method' => 'reducing_balance',
        'is_active'                   => true,
    ]);
}

function bntApplication(Borrower $borrower, string $status): LoanApplication
{
    static $count = 0;
    $count++;

    return LoanApplication::create([
        'application_number'    => 'APP-BNT-' . str_pad((string) $count, 5, '0', STR_PAD_LEFT),
        'borrower_id'           => $borrower->id,
        'loan_product_id'       => bntProduct()->id,
        'requested_principal'   => '5000.00',
        'requested_tenure_days' => 30,
        'status'                => $status,
    ]);
}

function bntUpload(Borrower $borrower, LoanApplication $application): MpesaStatementUpload
{
    return MpesaStatementUpload::create([
        'borrower_id'         => $borrower->id,
        'loan_application_id' => $application->id,
        'file_path'           => 'statements/bnt.pdf',
        'review_status'       => StatementReviewStatus::AwaitingReview->value,
        'flags'               => [],
    ]);
}

function bntDocument(Borrower $borrower): BorrowerKycDocument
{
    return BorrowerKycDocument::create([
        'borrower_id'         => $borrower->id,
        'document_type'       => 'id_front',
        'file_path'           => 'kyc/bnt.png',
        'verification_status' => VerificationStatus::PENDING->value,
    ]);
}

function bntAlert(Borrower $borrower, string $title, ?string $readAt = null): BorrowerNotification
{
    return BorrowerNotification::create([
        'borrower_id' => $borrower->id,
        'kind'        => 'test',
        'title'       => $title,
        'body'        => 'Body of ' . $title,
        'read_at'     => $readAt,
    ]);
}

it('keeps guests and staff out of the alerts page', function (): void {
    $this->get('/borrower/notifications')->assertRedirect('/login');

    $this->actingAs(bntUser('1', 'finance_officer'))
        ->get('/borrower/notifications')
        ->assertForbidden();
});

it('shows a borrower only their own alerts', function (): void {
    $user = bntUser('2');
    $mine = bntBorrower('2', $user);
    $theirs = bntBorrower('3');

    bntAlert($mine, 'Mine alert');
    bntAlert($theirs, 'Theirs alert');

    $this->actingAs($user)
        ->get('/borrower/notifications')
        ->assertOk()
        ->assertSee('Mine alert')
        ->assertDontSee('Theirs alert');
});

it('counts unread alerts in the header and marks only your own as read', function (): void {
    $user = bntUser('4');
    $mine = bntBorrower('4', $user);
    $theirs = bntBorrower('5');

    bntAlert($mine, 'One');
    bntAlert($mine, 'Two');
    bntAlert($theirs, 'Other');

    $html = $this->actingAs($user)->get('/borrower/notifications')->assertOk()->getContent();
    expect((string) $html)->toMatch('/Alerts\s*<span[^>]*>\s*2\s*<\/span>/');

    $this->actingAs($user)
        ->post('/borrower/notifications/read')
        ->assertRedirect(route('borrower.notifications'));

    expect(BorrowerNotification::query()->where('borrower_id', $mine->id)->whereNull('read_at')->count())->toBe(0);
    expect(BorrowerNotification::query()->where('borrower_id', $theirs->id)->whereNull('read_at')->count())->toBe(1);

    $after = $this->actingAs($user)->get('/borrower/notifications')->assertOk()->getContent();
    expect((string) $after)->not->toMatch('/Alerts\s*<span/');
});

it('tells the borrower when a statement is accepted', function (): void {
    $borrower = bntBorrower('6');
    $application = bntApplication($borrower, 'pending');
    $upload = bntUpload($borrower, $application);
    $officer = bntUser('60', 'credit_officer');

    app(StatementReviewService::class)->accept($upload->id, $officer->id, '10000.00', null, '127.0.0.1', 'test');

    $alert = BorrowerNotification::query()->where('borrower_id', $borrower->id)->firstOrFail();
    expect($alert->kind)->toBe('statement_accepted');
    expect($alert->read_at)->toBeNull();
});

it('tells the borrower to upload again without repeating the staff reason', function (): void {
    $borrower = bntBorrower('7');
    $application = bntApplication($borrower, 'pending');
    $upload = bntUpload($borrower, $application);
    $officer = bntUser('70', 'credit_officer');

    app(StatementReviewService::class)->reject($upload->id, $officer->id, 'Secret internal reason', '127.0.0.1', 'test');

    $alert = BorrowerNotification::query()->where('borrower_id', $borrower->id)->firstOrFail();
    expect($alert->kind)->toBe('statement_rejected');
    expect($alert->body)->not->toContain('Secret internal reason');
});

it('tells the borrower when the final approval is given', function (): void {
    $borrower = bntBorrower('8');
    $application = bntApplication($borrower, MakerCheckerService::APPLICATION_AWAITING_STATUS);
    $maker = bntUser('80', 'credit_officer');
    $checker = bntUser('81', 'finance_officer');

    $service = app(MakerCheckerService::class);
    $request = $service->createPendingRequest(
        $maker->id,
        MakerCheckerService::ACTION_APPROVE_LOAN_APPLICATION,
        LoanApplication::class,
        $application->id
    );
    $service->authorizeRequest($request->id, $checker->id, 'ok');

    $alert = BorrowerNotification::query()->where('borrower_id', $borrower->id)->firstOrFail();
    expect($alert->kind)->toBe('application_approved');
});

it('tells the borrower about a final rejection with only the fixed borrower-safe line', function (): void {
    $borrower = bntBorrower('9');
    $application = bntApplication($borrower, MakerCheckerService::APPLICATION_AWAITING_STATUS);
    $maker = bntUser('90', 'credit_officer');
    $checker = bntUser('91', 'finance_officer');

    $service = app(MakerCheckerService::class);
    $request = $service->createPendingRequest(
        $maker->id,
        MakerCheckerService::ACTION_APPROVE_LOAN_APPLICATION,
        LoanApplication::class,
        $application->id
    );
    $service->rejectRequest($request->id, $checker->id, 'Checker private note');

    $alert = BorrowerNotification::query()->where('borrower_id', $borrower->id)->firstOrFail();
    expect($alert->kind)->toBe('application_rejected');
    expect($alert->body)->toBe(MakerCheckerService::BORROWER_REJECTION_REASON);
    expect($alert->body)->not->toContain('Checker private note');
});

it('tells the borrower when an ID document is verified', function (): void {
    $borrower = bntBorrower('10');
    $document = bntDocument($borrower);
    $officer = bntUser('100', 'credit_officer');

    app(KycDocumentService::class)->verify($document->id, $officer->id, '127.0.0.1', 'test');

    $alert = BorrowerNotification::query()->where('borrower_id', $borrower->id)->firstOrFail();
    expect($alert->kind)->toBe('kyc_verified');
    expect($alert->body)->toContain('National ID - front');
});

it('tells the borrower to re-upload an ID document without the staff reason', function (): void {
    $borrower = bntBorrower('11');
    $document = bntDocument($borrower);
    $officer = bntUser('110', 'credit_officer');

    app(KycDocumentService::class)->reject($document->id, $officer->id, 'Blurry secret reason', '127.0.0.1', 'test');

    $alert = BorrowerNotification::query()->where('borrower_id', $borrower->id)->firstOrFail();
    expect($alert->kind)->toBe('kyc_rejected');
    expect($alert->body)->toContain('National ID - front');
    expect($alert->body)->not->toContain('Blurry secret reason');
});

it('tells the borrower when the money is sent', function (): void {
    foreach (['1010' => 'M-Pesa Settlement Account', '1200' => 'Loans Receivable'] as $code => $name) {
        ChartOfAccount::forceCreate([
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => 'ASSET',
            'currency'     => 'KES',
            'is_active'    => true,
        ]);
    }

    $borrower = bntBorrower('12');
    $application = bntApplication($borrower, 'approved');

    $loan = Loan::create([
        'loan_number'          => 'LN-BNT-001',
        'loan_application_id'  => $application->id,
        'borrower_id'          => $borrower->id,
        'loan_product_id'      => bntProduct()->id,
        'principal_disbursed'  => '5000.00',
        'annual_interest_rate' => '18.00',
        'tenure_days'          => 30,
        'status'               => LoanStatus::APPROVAL_HOLD,
        'disbursed_at'         => null,
        'due_date'             => null,
        'is_in_duplum_locked'  => false,
    ]);

    MpesaB2cDisbursement::forceCreate([
        'loan_id'                    => $loan->id,
        'originator_conversation_id' => 'B2C-BNT-ORIGIN',
        'conversation_id'            => 'B2C-BNT-CONV',
        'recipient_phone'            => $borrower->phone_number,
        'amount'                     => '5000.00',
        'status'                     => 'initiated',
    ]);

    app(B2cDisbursementService::class)->processCallback([
        'Result' => [
            'ResultCode'               => 0,
            'ResultDesc'               => 'Processed',
            'OriginatorConversationID' => 'B2C-BNT-ORIGIN',
            'ConversationID'           => 'B2C-BNT-CONV',
            'TransactionID'            => 'BNTTXN001',
        ],
    ]);

    expect($loan->fresh()->status)->toBe(LoanStatus::DISBURSED);

    $alert = BorrowerNotification::query()->where('borrower_id', $borrower->id)->firstOrFail();
    expect($alert->kind)->toBe('loan_disbursed');
    expect($alert->body)->toContain('KES 5,000.00');
    expect($alert->body)->toContain('LN-BNT-001');
});

it('never lets a failed alert undo the real action', function (): void {
    $borrower = bntBorrower('13');
    $document = bntDocument($borrower);
    $officer = bntUser('130', 'credit_officer');

    Schema::drop('borrower_notifications');

    app(KycDocumentService::class)->verify($document->id, $officer->id, '127.0.0.1', 'test');

    expect($document->fresh()->verification_status)->toBe(VerificationStatus::VERIFIED);
});