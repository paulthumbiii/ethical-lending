<?php

declare(strict_types=1);

namespace App\Http\Controllers\Borrower;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreKycDocumentRequest;
use App\Models\Borrower;
use App\Services\Customer\KycDocumentService;
use Illuminate\Http\RedirectResponse;

final class KycDocumentController extends Controller
{
    public function __construct(private readonly KycDocumentService $kyc)
    {
    }

    public function store(StoreKycDocumentRequest $request): RedirectResponse
    {
        $borrower = $request->user()?->borrower;

        if (! $borrower instanceof Borrower) {
            abort(404);
        }

        $this->kyc->store(
            $borrower,
            (int) $request->user()->id,
            (string) $request->validated('document_type'),
            $request->file('document'),
            (string) ($request->ip() ?? '127.0.0.1'),
            (string) ($request->userAgent() ?? 'unknown')
        );

        return redirect()->route('borrower.account')
            ->with('status', 'Document received. A credit officer will check it.');
    }
}