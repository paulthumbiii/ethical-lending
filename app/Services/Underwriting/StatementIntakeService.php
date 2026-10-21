<?php

declare(strict_types=1);

namespace App\Services\Underwriting;

use App\Enums\StatementReviewStatus;
use App\Models\AuditTrail;
use App\Models\LoanApplication;
use App\Models\MpesaStatementUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class StatementIntakeService
{
    public function store(
        int $borrowerId,
        int $applicationId,
        int $userId,
        UploadedFile $file,
        ?string $password,
        string $ip,
        string $userAgent
    ): MpesaStatementUpload {
        $path = (string) $file->getRealPath();

        // Real PDFs start with "%PDF-". A renamed image or text file does not.
        $head = (string) file_get_contents($path, false, null, 0, 5);
        if ($head !== '%PDF-') {
            throw ValidationException::withMessages([
                'statement' => 'That file is not a PDF. Upload the PDF exactly as Safaricom sent it.',
            ]);
        }

        $hash = (string) hash_file('sha256', $path);
        $size = (int) $file->getSize();
        $originalName = substr((string) $file->getClientOriginalName(), 0, 255);

        return DB::transaction(function () use (
            $borrowerId, $applicationId, $userId, $file, $password, $ip, $userAgent, $hash, $size, $originalName
        ): MpesaStatementUpload {
            $application = LoanApplication::query()
                ->where('id', $applicationId)
                ->where('borrower_id', $borrowerId)
                ->lockForUpdate()
                ->first();

            if ($application === null) {
                throw ValidationException::withMessages(['statement' => 'We could not find that application.']);
            }

            if (! in_array($application->status, ApplicationReviewService::CHECKABLE_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'statement' => 'You can only add a statement while your application is waiting for review.',
                ]);
            }

            $alreadyAccepted = MpesaStatementUpload::query()
                ->where('loan_application_id', $application->id)
                ->where('review_status', StatementReviewStatus::Accepted->value)
                ->exists();

            if ($alreadyAccepted) {
                throw ValidationException::withMessages(['statement' => 'Your statement has already been accepted.']);
            }

            /** @var list<string> $flags */
            $flags = [];

            $usedByOther = MpesaStatementUpload::query()
                ->where('file_sha256', $hash)
                ->where('borrower_id', '!=', $borrowerId)
                ->exists();
            if ($usedByOther) {
                $flags[] = 'same_file_other_borrower';
            }

            $usedEarlier = MpesaStatementUpload::query()
                ->where('file_sha256', $hash)
                ->where('borrower_id', $borrowerId)
                ->where('loan_application_id', '!=', $application->id)
                ->exists();
            if ($usedEarlier) {
                $flags[] = 'same_file_earlier_application';
            }

            // A newer upload replaces one still waiting. Rejected ones stay as history.
            MpesaStatementUpload::query()
                ->where('loan_application_id', $application->id)
                ->where('review_status', StatementReviewStatus::AwaitingReview->value)
                ->update(['review_status' => StatementReviewStatus::Superseded->value]);

            $stored = Storage::disk('local')->putFileAs(
                'statements/' . $borrowerId,
                $file,
                Str::random(40) . '.pdf'
            );

            if ($stored === false) {
                throw new RuntimeException('Could not store the statement file.');
            }

            $upload = MpesaStatementUpload::create([
                'borrower_id' => $borrowerId,
                'loan_application_id' => $application->id,
                'file_path' => $stored,
                'original_name' => $originalName,
                'file_size' => $size,
                'file_sha256' => $hash,
                'pdf_password_encrypted' => ($password !== null && $password !== '')
                    ? Crypt::encryptString($password)
                    : null,
                'parsing_status' => 'pending',
                'review_status' => StatementReviewStatus::AwaitingReview->value,
                'flags' => $flags,
            ]);

            AuditTrail::create([
                'user_id' => $userId,
                'action' => 'STATEMENT_UPLOADED',
                'auditable_type' => MpesaStatementUpload::class,
                'auditable_id' => $upload->id,
                'old_values' => null,
                'new_values' => [
                    'application_id' => $application->id,
                    'sha256' => $hash,
                    'size_bytes' => $size,
                    'flags' => $flags,
                    'consent_given' => true,
                    'password_supplied' => $password !== null && $password !== '',
                ],
                'ip_address' => substr($ip, 0, 45),
                'user_agent' => substr($userAgent, 0, 255),
            ]);

            return $upload;
        });
    }
}