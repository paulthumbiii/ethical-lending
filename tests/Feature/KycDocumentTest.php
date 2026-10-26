<?php

declare(strict_types=1);

use App\Enums\VerificationStatus;
use App\Models\AuditTrail;
use App\Models\Borrower;
use App\Models\BorrowerKycDocument;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(RolesSeeder::class);
    Storage::fake('local');
});

function kycUser(string $n, string $role): User
{
    $user = User::create([
        'name'         => 'Kyc User ' . $n,
        'email'        => 'kyc' . $n . '@kyc.test',
        'password'     => 'Demo@12345',
        'phone_number' => '254744' . str_pad($n, 6, '0', STR_PAD_LEFT),
        'is_active'    => true,
    ]);
    $user->assignRole($role);

    return $user;
}

function kycBorrower(string $n, ?User $user): Borrower
{
    return Borrower::create([
        'user_id'        => $user?->id,
        'full_name'      => 'Kyc Borrower ' . $n,
        'id_number'      => '3957' . str_pad($n, 4, '0', STR_PAD_LEFT),
        'phone_number'   => '254755' . str_pad($n, 6, '0', STR_PAD_LEFT),
        'credit_limit'   => '5000.00',
        'risk_band'      => 'B',
        'is_blacklisted' => false,
    ]);
}

function kycImage(string $name = 'front.png'): UploadedFile
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

    return UploadedFile::fake()->createWithContent($name, (string) $png);
}

function kycDocument(Borrower $borrower, string $type = 'id_front', VerificationStatus $status = VerificationStatus::PENDING): BorrowerKycDocument
{
    $path = 'kyc/' . $borrower->id . '/seed-' . $type . '.png';
    Storage::disk('local')->put($path, 'seed-bytes');

    return BorrowerKycDocument::create([
        'borrower_id'         => $borrower->id,
        'document_type'       => $type,
        'file_path'           => $path,
        'verification_status' => $status->value,
    ]);
}

it('stores an uploaded ID privately as pending and audits it', function (): void {
    $user = kycUser('1', 'borrower');
    $borrower = kycBorrower('1', $user);

    $this->actingAs($user)
        ->post(route('borrower.documents.store'), ['document_type' => 'id_front', 'document' => kycImage()])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('borrower.account'));

    $doc = BorrowerKycDocument::query()->firstOrFail();

    expect((int) $doc->borrower_id)->toBe((int) $borrower->id);
    expect($doc->verification_status)->toBe(VerificationStatus::PENDING);
    expect($doc->file_path)->toStartWith('kyc/' . $borrower->id . '/');
    Storage::disk('local')->assertExists($doc->file_path);

    $audit = AuditTrail::query()->where('action', 'KYC_DOCUMENT_UPLOADED')->firstOrFail();
    expect((int) $audit->auditable_id)->toBe((int) $doc->id);
    expect($audit->new_values['document_type'])->toBe('id_front');
});

it('refuses disguised files and unknown document types', function (): void {
    $user = kycUser('2', 'borrower');
    kycBorrower('2', $user);

    $disguised = UploadedFile::fake()->createWithContent('id.jpg', 'plain text pretending to be a photo');
    $this->actingAs($user)
        ->post(route('borrower.documents.store'), ['document_type' => 'id_front', 'document' => $disguised])
        ->assertSessionHasErrors('document');

    $this->actingAs($user)
        ->post(route('borrower.documents.store'), ['document_type' => 'passport', 'document' => kycImage()])
        ->assertSessionHasErrors('document_type');

    expect(BorrowerKycDocument::query()->count())->toBe(0);
});

it('replaces a pending document and deletes the old file', function (): void {
    $user = kycUser('3', 'borrower');
    $borrower = kycBorrower('3', $user);
    $existing = kycDocument($borrower);
    $oldPath = $existing->file_path;

    $this->actingAs($user)
        ->post(route('borrower.documents.store'), ['document_type' => 'id_front', 'document' => kycImage()])
        ->assertSessionHasNoErrors();

    expect(BorrowerKycDocument::query()->count())->toBe(1);
    $fresh = BorrowerKycDocument::query()->firstOrFail();
    expect($fresh->file_path)->not->toBe($oldPath);
    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists($fresh->file_path);
});

it('locks a verified document against replacement', function (): void {
    $user = kycUser('4', 'borrower');
    $borrower = kycBorrower('4', $user);
    $existing = kycDocument($borrower, 'id_front', VerificationStatus::VERIFIED);

    $this->actingAs($user)
        ->post(route('borrower.documents.store'), ['document_type' => 'id_front', 'document' => kycImage()])
        ->assertSessionHasErrors('document');

    expect($existing->fresh()->file_path)->toBe($existing->file_path);
    expect(BorrowerKycDocument::query()->count())->toBe(1);
});

it('lets a credit officer open the file with safe headers and records the view', function (): void {
    $borrower = kycBorrower('5', null);
    $doc = kycDocument($borrower);
    $officer = kycUser('50', 'credit_officer');

    $response = $this->actingAs($officer)
        ->get(route('lender.borrowers.documents.file', ['borrower' => $borrower->id, 'document' => $doc->id]))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    expect(AuditTrail::query()->where('action', 'KYC_DOCUMENT_VIEWED')->count())->toBe(1);
});

it('keeps support and finance staff away from the documents', function (): void {
    $borrower = kycBorrower('6', null);
    $doc = kycDocument($borrower);
    $url = route('lender.borrowers.documents.file', ['borrower' => $borrower->id, 'document' => $doc->id]);

    $this->actingAs(kycUser('60', 'support_agent'))->get($url)->assertForbidden();
    $this->actingAs(kycUser('61', 'finance_officer'))->get($url)->assertForbidden();

    expect(AuditTrail::query()->where('action', 'KYC_DOCUMENT_VIEWED')->count())->toBe(0);
});

it('returns 404 when the document belongs to a different borrower', function (): void {
    $owner = kycBorrower('7', null);
    $other = kycBorrower('8', null);
    $doc = kycDocument($owner);

    $this->actingAs(kycUser('70', 'credit_officer'))
        ->get(route('lender.borrowers.documents.file', ['borrower' => $other->id, 'document' => $doc->id]))
        ->assertNotFound();
});

it('verifies a pending document and audits who decided', function (): void {
    $borrower = kycBorrower('9', null);
    $doc = kycDocument($borrower);
    $officer = kycUser('90', 'credit_officer');

    $this->actingAs($officer)
        ->post(route('lender.borrowers.documents.verify', ['borrower' => $borrower->id, 'document' => $doc->id]))
        ->assertSessionHasNoErrors();

    $fresh = $doc->fresh();
    expect($fresh->verification_status)->toBe(VerificationStatus::VERIFIED);
    expect((int) $fresh->verified_by_user_id)->toBe((int) $officer->id);
    expect(AuditTrail::query()->where('action', 'KYC_DOCUMENT_VERIFIED')->count())->toBe(1);

    // A decided document cannot be decided again.
    $this->actingAs($officer)
        ->post(route('lender.borrowers.documents.verify', ['borrower' => $borrower->id, 'document' => $doc->id]))
        ->assertSessionHasErrors('review');
});

it('needs a reason to reject, hides it from the borrower, and lets them upload again', function (): void {
    $user = kycUser('10', 'borrower');
    $borrower = kycBorrower('10', $user);
    $doc = kycDocument($borrower);
    $officer = kycUser('100', 'credit_officer');
    $url = route('lender.borrowers.documents.reject', ['borrower' => $borrower->id, 'document' => $doc->id]);

    $this->actingAs($officer)->post($url, ['reason' => ''])->assertSessionHasErrors('reason');
    expect($doc->fresh()->verification_status)->toBe(VerificationStatus::PENDING);

    $this->actingAs($officer)->post($url, ['reason' => 'Blurry corner, name unreadable'])->assertSessionHasNoErrors();

    expect($doc->fresh()->verification_status)->toBe(VerificationStatus::REJECTED);
    expect($doc->fresh()->review_notes)->toBe('Blurry corner, name unreadable');
    expect(AuditTrail::query()->where('action', 'KYC_DOCUMENT_REJECTED')->count())->toBe(1);

    $this->actingAs($user)
        ->get('/borrower/account')
        ->assertOk()
        ->assertSee('clearer photo')
        ->assertDontSee('Blurry corner');

    $this->actingAs($user)
        ->post(route('borrower.documents.store'), ['document_type' => 'id_front', 'document' => kycImage()])
        ->assertSessionHasNoErrors();

    $fresh = $doc->fresh();
    expect($fresh->verification_status)->toBe(VerificationStatus::PENDING);
    expect($fresh->review_notes)->toBeNull();
});

it('shows the Documents tab only to staff who can see statements', function (): void {
    $borrower = kycBorrower('11', null);
    $url = route('lender.borrowers.show', ['borrower' => $borrower->id]);

    $this->actingAs(kycUser('110', 'credit_officer'))->get($url)->assertOk()->assertSee('tab=documents');
    $this->actingAs(kycUser('111', 'support_agent'))->get($url)->assertOk()->assertDontSee('tab=documents');
});