<?php

declare(strict_types=1);

use App\Enums\StatementReviewStatus;
use App\Enums\UserRole;
use App\Models\AuditTrail;
use App\Models\Borrower;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\MpesaStatementUpload;
use App\Models\User;
use App\Services\Underwriting\MpesaStatementParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** @return array{0: User, 1: Borrower, 2: LoanApplication} */
function stuSetup(string $status = 'pending'): array
{
    static $n = 0;
    $n++;

    Role::findOrCreate(UserRole::Borrower->value, 'web');

    $user = User::create([
        'name' => 'Stu Borrower ' . $n,
        'email' => "stu{$n}@example.test",
        'password' => 'Secret@12345',
        'phone_number' => '25473' . str_pad((string) $n, 7, '0', STR_PAD_LEFT),
        'is_active' => true,
    ]);
    $user->assignRole(UserRole::Borrower->value);

    $borrower = Borrower::create([
        'user_id' => $user->id,
        'full_name' => 'Stu Borrower ' . $n,
        'id_number' => '6' . str_pad((string) $n, 7, '0', STR_PAD_LEFT),
        'phone_number' => '25473' . str_pad((string) $n, 7, '0', STR_PAD_LEFT),
        'credit_limit' => '0.00',
        'risk_band' => 'C',
        'is_blacklisted' => false,
    ]);

    $product = LoanProduct::firstOrCreate(
        ['product_code' => 'STU-TEST'],
        [
            'name' => 'Stu Test Loan',
            'min_principal' => '1000.00',
            'max_principal' => '20000.00',
            'default_tenure_days' => 30,
            'interest_calculation_method' => 'reducing_balance',
            'is_active' => true,
        ]
    );

    $application = LoanApplication::create([
        'application_number' => 'APP-STU-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT),
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'requested_principal' => '5000.00',
        'requested_tenure_days' => 30,
        'status' => $status,
    ]);

    return [$user, $borrower, $application];
}

function stuPdf(string $body = 'one'): UploadedFile
{
    return UploadedFile::fake()->createWithContent('statement.pdf', "%PDF-1.4\n% fake statement {$body}\n%%EOF");
}

/** @return array<string, mixed> */
function stuPayload(UploadedFile $file, array $override = []): array
{
    return array_merge([
        'statement' => $file,
        'statement_password' => 'S3cret-pass',
        'consent' => '1',
    ], $override);
}

function stuUrl(LoanApplication $application): string
{
    return route('borrower.statement.store', ['loanApplication' => $application->id]);
}

it('stores the statement privately, hashes it, encrypts the password and audits it', function () {
    Storage::fake('local');
    [$user, $borrower, $application] = stuSetup();
    $file = stuPdf();
    $hash = hash_file('sha256', (string) $file->getRealPath());

    $this->actingAs($user)
        ->post(stuUrl($application), stuPayload($file))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('borrower.apply.create'));

    $upload = MpesaStatementUpload::query()->firstOrFail();

    expect($upload->review_status)->toBe(StatementReviewStatus::AwaitingReview);
    expect((int) $upload->borrower_id)->toBe((int) $borrower->id);
    expect((int) $upload->loan_application_id)->toBe((int) $application->id);
    expect($upload->file_sha256)->toBe($hash);
    expect($upload->pdf_password_encrypted)->not->toBe('S3cret-pass');
    expect(Crypt::decryptString((string) $upload->pdf_password_encrypted))->toBe('S3cret-pass');
    Storage::disk('local')->assertExists($upload->file_path);

    $audit = AuditTrail::query()->where('action', 'STATEMENT_UPLOADED')->firstOrFail();

    expect((int) $audit->auditable_id)->toBe((int) $upload->id);
    expect($audit->new_values['consent_given'])->toBeTrue();
    expect(json_encode($audit->new_values))->not->toContain('S3cret-pass');
});

it('refuses files that are not real PDFs', function () {
    Storage::fake('local');
    [$user, , $application] = stuSetup();

    $disguised = UploadedFile::fake()->createWithContent('statement.pdf', 'just some text, not a pdf');
    $this->actingAs($user)
        ->post(stuUrl($application), stuPayload($disguised))
        ->assertSessionHasErrors('statement');

    $wrongName = UploadedFile::fake()->createWithContent('photo.jpg', "%PDF-1.4\n%%EOF");
    $this->actingAs($user)
        ->post(stuUrl($application), stuPayload($wrongName))
        ->assertSessionHasErrors('statement');

    expect(MpesaStatementUpload::query()->count())->toBe(0);
});

it('requires consent before the statement is processed', function () {
    Storage::fake('local');
    [$user, , $application] = stuSetup();

    $this->actingAs($user)
        ->post(stuUrl($application), stuPayload(stuPdf(), ['consent' => null]))
        ->assertSessionHasErrors('consent');

    expect(MpesaStatementUpload::query()->count())->toBe(0);
});

it('returns 404 when a borrower targets someone else\'s application', function () {
    Storage::fake('local');
    [$userA] = stuSetup();
    [, , $applicationB] = stuSetup();

    $this->actingAs($userA)
        ->post(stuUrl($applicationB), stuPayload(stuPdf()))
        ->assertNotFound();

    expect(MpesaStatementUpload::query()->count())->toBe(0);
});

it('replaces an upload that is still waiting when a newer one arrives', function () {
    Storage::fake('local');
    [$user, , $application] = stuSetup();

    $this->actingAs($user)->post(stuUrl($application), stuPayload(stuPdf('first')))->assertSessionHasNoErrors();
    $this->actingAs($user)->post(stuUrl($application), stuPayload(stuPdf('second')))->assertSessionHasNoErrors();

    $uploads = MpesaStatementUpload::query()->orderBy('id')->get();

    expect($uploads)->toHaveCount(2);
    expect($uploads[0]->review_status)->toBe(StatementReviewStatus::Superseded);
    expect($uploads[1]->review_status)->toBe(StatementReviewStatus::AwaitingReview);
});

it('flags a file already used by another borrower without telling the borrower', function () {
    Storage::fake('local');
    [$userA, , $applicationA] = stuSetup();
    [$userB, , $applicationB] = stuSetup();

    $this->actingAs($userA)->post(stuUrl($applicationA), stuPayload(stuPdf('shared')))->assertSessionHasNoErrors();
    $this->actingAs($userB)->post(stuUrl($applicationB), stuPayload(stuPdf('shared')))->assertSessionHasNoErrors();

    $first = MpesaStatementUpload::query()->where('loan_application_id', $applicationA->id)->firstOrFail();
    $second = MpesaStatementUpload::query()->where('loan_application_id', $applicationB->id)->firstOrFail();

    expect($first->flags)->toBe([]);
    expect($second->flags)->toContain('same_file_other_borrower');
});

it('refuses uploads once the application is no longer waiting for review', function () {
    Storage::fake('local');
    [$user, , $application] = stuSetup('approved_pending_checker');

    $this->actingAs($user)
        ->post(stuUrl($application), stuPayload(stuPdf()))
        ->assertSessionHasErrors('statement');

    expect(MpesaStatementUpload::query()->count())->toBe(0);
});

it('refuses a new upload after the statement was accepted', function () {
    Storage::fake('local');
    [$user, $borrower, $application] = stuSetup();

    MpesaStatementUpload::create([
        'borrower_id' => $borrower->id,
        'loan_application_id' => $application->id,
        'file_path' => 'statements/x.pdf',
        'review_status' => StatementReviewStatus::Accepted->value,
    ]);

    $this->actingAs($user)
        ->post(stuUrl($application), stuPayload(stuPdf('late')))
        ->assertSessionHasErrors('statement');

    expect(MpesaStatementUpload::query()->count())->toBe(1);
});

it('shows the borrower where their statement stands', function () {
    $this->withoutVite();
    Storage::fake('local');
    [$user, , $application] = stuSetup();

    $this->actingAs($user)
        ->get(route('borrower.apply.create'))
        ->assertOk()
        ->assertSee('Your M-Pesa statement')
        ->assertSee('Upload statement');

    $this->actingAs($user)->post(stuUrl($application), stuPayload(stuPdf()))->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->get(route('borrower.apply.create'))
        ->assertOk()
        ->assertSee('Being checked');

    MpesaStatementUpload::query()->firstOrFail()->update([
        'review_status' => StatementReviewStatus::Rejected->value,
        'review_notes' => 'The name on the statement does not match your registered name.',
    ]);

    $this->actingAs($user)
        ->get(route('borrower.apply.create'))
        ->assertOk()
        ->assertSee('Please upload again')
        ->assertSee('does not match your registered name')
        ->assertSee('Upload statement');
});

it('never invents income when a statement file is missing outside testing', function () {
    [, $borrower] = stuSetup();

    $upload = MpesaStatementUpload::create([
        'borrower_id' => $borrower->id,
        'file_path' => 'statements/does-not-exist.pdf',
    ]);

    $this->app->detectEnvironment(fn () => 'production');

    try {
        expect(fn () => $this->app->make(MpesaStatementParserService::class)->parseStatement($upload->id, '12345678'))
            ->toThrow(RuntimeException::class);
    } finally {
        $this->app->detectEnvironment(fn () => 'testing');
    }

    $this->assertDatabaseMissing('borrower_financial_profiles', ['borrower_id' => $borrower->id]);
});