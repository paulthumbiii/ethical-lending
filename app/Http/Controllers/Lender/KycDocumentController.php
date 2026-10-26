<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lender;

use App\Http\Controllers\Controller;
use App\Http\Requests\RejectKycDocumentRequest;
use App\Models\Borrower;
use App\Models\BorrowerKycDocument;
use App\Services\Customer\KycDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class KycDocumentController extends Controller
{
    public function __construct(private readonly KycDocumentService $kyc)
    {
    }

    public function file(Request $request, Borrower $borrower, BorrowerKycDocument $document): StreamedResponse
    {
        $this->owned($borrower, $document);

        $disk = Storage::disk('local');

        if (! $disk->exists($document->file_path)) {
            abort(404);
        }

        $this->kyc->recordView(
            $document,
            (int) $request->user()->id,
            (string) ($request->ip() ?? '127.0.0.1'),
            (string) ($request->userAgent() ?? 'unknown')
        );

        $ext = strtolower(pathinfo($document->file_path, PATHINFO_EXTENSION));
        $type = match ($ext) {
            'pdf'         => 'application/pdf',
            'png'         => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default       => 'application/octet-stream',
        };

        return $disk->response(
            $document->file_path,
            'kyc-' . $document->id . '.' . $ext,
            [
                'Content-Type'           => $type,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control'          => 'private, no-store',
            ]
        );
    }

    public function verify(Request $request, Borrower $borrower, BorrowerKycDocument $document): RedirectResponse
    {
        $this->owned($borrower, $document);

        try {
            $this->kyc->verify(
                $document->id,
                (int) $request->user()->id,
                (string) ($request->ip() ?? '127.0.0.1'),
                (string) ($request->userAgent() ?? 'unknown')
            );
        } catch (RuntimeException $e) {
            return $this->back($borrower)->withErrors(['review' => $e->getMessage()]);
        }

        return $this->back($borrower)->with('status', 'Document verified.');
    }

    public function reject(RejectKycDocumentRequest $request, Borrower $borrower, BorrowerKycDocument $document): RedirectResponse
    {
        $this->owned($borrower, $document);

        try {
            $this->kyc->reject(
                $document->id,
                (int) $request->user()->id,
                (string) $request->validated('reason'),
                (string) ($request->ip() ?? '127.0.0.1'),
                (string) ($request->userAgent() ?? 'unknown')
            );
        } catch (RuntimeException $e) {
            return $this->back($borrower)->withErrors(['review' => $e->getMessage()]);
        }

        return $this->back($borrower)->with('status', 'Document rejected. The borrower can upload a new one.');
    }

    private function owned(Borrower $borrower, BorrowerKycDocument $document): void
    {
        if ((int) $document->borrower_id !== (int) $borrower->id) {
            abort(404);
        }
    }

    private function back(Borrower $borrower): RedirectResponse
    {
        return redirect()->route('lender.borrowers.show', ['borrower' => $borrower->id, 'tab' => 'documents']);
    }
}