<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\Enums\VerificationStatus;
use App\Models\AuditTrail;
use App\Models\Borrower;
use App\Models\BorrowerKycDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class KycDocumentService
{
    public function store(
        Borrower $borrower,
        int $userId,
        string $type,
        UploadedFile $file,
        string $ip,
        string $userAgent
    ): BorrowerKycDocument {
        $path = (string) $file->getRealPath();

        // Trust the file's first bytes, not its name or the browser's claim.
        $ext = $this->detectExtension($path);
        if ($ext === null) {
            throw ValidationException::withMessages([
                'document' => 'Upload a JPG, PNG or PDF file.',
            ]);
        }

        $hash = (string) hash_file('sha256', $path);
        $size = (int) $file->getSize();
        $oldPath = null;

        $document = DB::transaction(function () use (
            $borrower, $userId, $type, $file, $ip, $userAgent, $hash, $size, $ext, &$oldPath
        ): BorrowerKycDocument {
            // Serialise uploads for this borrower.
            Borrower::query()->whereKey($borrower->id)->lockForUpdate()->first();

            $existing = BorrowerKycDocument::query()
                ->where('borrower_id', $borrower->id)
                ->where('document_type', $type)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->verification_status === VerificationStatus::VERIFIED) {
                throw ValidationException::withMessages([
                    'document' => 'This document is already verified, so it cannot be replaced.',
                ]);
            }

            $stored = Storage::disk('local')->putFileAs(
                'kyc/' . $borrower->id,
                $file,
                Str::random(40) . '.' . $ext
            );

            if ($stored === false) {
                throw new RuntimeException('Could not store the document.');
            }

            $replaced = $existing !== null;

            if ($existing !== null) {
                $oldPath = $existing->file_path;
                $existing->update([
                    'file_path'           => $stored,
                    'verification_status' => VerificationStatus::PENDING->value,
                    'verified_by_user_id' => null,
                    'verified_at'         => null,
                    'review_notes'        => null,
                ]);
                $document = $existing;
            } else {
                $document = BorrowerKycDocument::create([
                    'borrower_id'         => $borrower->id,
                    'document_type'       => $type,
                    'file_path'           => $stored,
                    'verification_status' => VerificationStatus::PENDING->value,
                ]);
            }

            $this->audit($userId, 'KYC_DOCUMENT_UPLOADED', $document->id, [
                'borrower_id'   => $borrower->id,
                'document_type' => $type,
                'sha256'        => $hash,
                'size_bytes'    => $size,
                'replaced'      => $replaced,
            ], $ip, $userAgent);

            return $document;
        });

        if ($oldPath !== null) {
            Storage::disk('local')->delete($oldPath);
        }

        return $document;
    }

    public function recordView(BorrowerKycDocument $document, int $userId, string $ip, string $userAgent): void
    {
        $this->audit($userId, 'KYC_DOCUMENT_VIEWED', $document->id, [
            'borrower_id'   => $document->borrower_id,
            'document_type' => $document->document_type,
        ], $ip, $userAgent);
    }

    public function verify(int $documentId, int $userId, string $ip, string $userAgent): BorrowerKycDocument
    {
        return $this->decide($documentId, $userId, true, null, $ip, $userAgent);
    }

    public function reject(int $documentId, int $userId, string $reason, string $ip, string $userAgent): BorrowerKycDocument
    {
        return $this->decide($documentId, $userId, false, $reason, $ip, $userAgent);
    }

    /** Returns jpg, png or pdf from the file's first bytes, or null if it is none of them. */
    private function detectExtension(string $path): ?string
    {
        $head = (string) file_get_contents($path, false, null, 0, 8);

        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'jpg';
        }

        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return 'png';
        }

        if (str_starts_with($head, '%PDF-')) {
            return 'pdf';
        }

        return null;
    }

    private function decide(
        int $documentId,
        int $userId,
        bool $verify,
        ?string $reason,
        string $ip,
        string $userAgent
    ): BorrowerKycDocument {
        return DB::transaction(function () use ($documentId, $userId, $verify, $reason, $ip, $userAgent): BorrowerKycDocument {
            $document = BorrowerKycDocument::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();

            if ($document->verification_status !== VerificationStatus::PENDING) {
                throw new RuntimeException('Only a document that is waiting for review can be decided.');
            }

            $document->update([
                'verification_status' => ($verify ? VerificationStatus::VERIFIED : VerificationStatus::REJECTED)->value,
                'verified_by_user_id' => $userId,
                'verified_at'         => now(),
                'review_notes'        => $verify ? null : $reason,
            ]);

            $this->audit(
                $userId,
                $verify ? 'KYC_DOCUMENT_VERIFIED' : 'KYC_DOCUMENT_REJECTED',
                $document->id,
                [
                    'borrower_id'   => $document->borrower_id,
                    'document_type' => $document->document_type,
                    'reason'        => $verify ? null : $reason,
                ],
                $ip,
                $userAgent
            );

            // The borrower is told the outcome only. The staff reason never goes into the message.
            $label = $this->label((string) $document->document_type);

            app(BorrowerNotificationService::class)->notify(
                (int) $document->borrower_id,
                $verify ? 'kyc_verified' : 'kyc_rejected',
                $verify ? 'ID document verified' : 'Please upload a clearer photo',
                $verify
                    ? sprintf('%s was verified.', $label)
                    : sprintf('Please upload a clearer photo of: %s. Open Account to replace it.', $label)
            );

            return $document;
        });
    }

    private function label(string $type): string
    {
        $label = BorrowerKycDocument::TYPES[$type] ?? 'Your document';

        return trim(str_replace('(optional)', '', $label));
    }

    /** @param array<string, mixed> $values */
    private function audit(int $userId, string $action, int $documentId, array $values, string $ip, string $userAgent): void
    {
        AuditTrail::create([
            'user_id'        => $userId,
            'action'         => $action,
            'auditable_type' => BorrowerKycDocument::class,
            'auditable_id'   => $documentId,
            'old_values'     => null,
            'new_values'     => $values,
            'ip_address'     => substr($ip, 0, 45),
            'user_agent'     => substr($userAgent, 0, 255),
        ]);
    }
}