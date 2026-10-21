<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lender;

use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptStatementRequest;
use App\Http\Requests\RejectStatementRequest;
use App\Models\MpesaStatementUpload;
use App\Services\Underwriting\StatementReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StatementReviewController extends Controller
{
    public function __construct(private readonly StatementReviewService $service)
    {
    }

    public function file(Request $request, MpesaStatementUpload $mpesaStatementUpload): StreamedResponse
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($mpesaStatementUpload->file_path)) {
            abort(404);
        }

        $this->service->recordView(
            $mpesaStatementUpload->id,
            (int) $request->user()->id,
            (string) ($request->ip() ?? '127.0.0.1'),
            (string) ($request->userAgent() ?? 'unknown')
        );

        return $disk->response(
            $mpesaStatementUpload->file_path,
            'statement-' . $mpesaStatementUpload->id . '.pdf',
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]
        );
    }

    public function password(Request $request, MpesaStatementUpload $mpesaStatementUpload): RedirectResponse
    {
        $target = $this->target($mpesaStatementUpload);

        $password = $this->service->revealPassword(
            $mpesaStatementUpload->id,
            (int) $request->user()->id,
            (string) ($request->ip() ?? '127.0.0.1'),
            (string) ($request->userAgent() ?? 'unknown')
        );

        if ($password === null) {
            return $target->withErrors(['review' => 'No password is stored for this statement.']);
        }

        return $target
            ->with('statement_password', $password)
            ->with('statement_password_for', $mpesaStatementUpload->id);
    }

    public function accept(AcceptStatementRequest $request, MpesaStatementUpload $mpesaStatementUpload): RedirectResponse
    {
        $target = $this->target($mpesaStatementUpload);

        try {
            $this->service->accept(
                $mpesaStatementUpload->id,
                (int) $request->user()->id,
                (string) $request->validated('verified_inflow'),
                $request->validated('notes'),
                (string) ($request->ip() ?? '127.0.0.1'),
                (string) ($request->userAgent() ?? 'unknown')
            );
        } catch (RuntimeException $e) {
            return $target->withErrors(['review' => $e->getMessage()]);
        }

        return $target->with('status', 'Statement accepted. You can now run the credit check.');
    }

    public function reject(RejectStatementRequest $request, MpesaStatementUpload $mpesaStatementUpload): RedirectResponse
    {
        $target = $this->target($mpesaStatementUpload);

        try {
            $this->service->reject(
                $mpesaStatementUpload->id,
                (int) $request->user()->id,
                (string) $request->validated('reason'),
                (string) ($request->ip() ?? '127.0.0.1'),
                (string) ($request->userAgent() ?? 'unknown')
            );
        } catch (RuntimeException $e) {
            return $target->withErrors(['review' => $e->getMessage()]);
        }

        return $target->with('status', 'Statement rejected. The borrower can see your reason and upload again.');
    }

    private function target(MpesaStatementUpload $upload): RedirectResponse
    {
        if ($upload->loan_application_id === null) {
            return redirect()->route('lender.applications.index');
        }

        return redirect()->route('lender.applications.show', ['loanApplication' => $upload->loan_application_id]);
    }
}