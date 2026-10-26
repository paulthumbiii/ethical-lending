<?php

declare(strict_types=1);

namespace App\Http\Controllers\Borrower;

use App\Enums\LoanStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\Borrower;
use App\Services\Servicing\ContractGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

final class ContractController extends Controller
{
    public function __construct(private readonly ContractGeneratorService $contracts)
    {
    }

    public function show(Request $request, int $loan): Response
    {
        $borrower = $request->user()?->borrower;

        if (! $borrower instanceof Borrower) {
            abort(404);
        }

        $loanModel = $borrower->loans()->whereKey($loan)->first();

        // The fee is only added at payout, so there is no final contract before then.
        if ($loanModel === null || in_array($loanModel->status, [LoanStatus::PENDING, LoanStatus::APPROVAL_HOLD], true)) {
            abort(404);
        }

        $contract = $this->contracts->generateContract($loanModel);
        $html = Storage::disk('local')->get((string) $contract->contract_pdf_path);

        if ($html === null) {
            abort(404);
        }

        AuditTrail::create([
            'user_id'        => (int) $request->user()->id,
            'action'         => 'LEGAL_CONTRACT_DOWNLOADED',
            'auditable_type' => $contract::class,
            'auditable_id'   => $contract->id,
            'old_values'     => null,
            'new_values'     => ['loan_id' => $loanModel->id],
            'ip_address'     => substr((string) ($request->ip() ?? '127.0.0.1'), 0, 45),
            'user_agent'     => substr((string) ($request->userAgent() ?? 'unknown'), 0, 255),
        ]);

        return response($html, 200, [
            'Content-Type'            => 'text/html; charset=UTF-8',
            'Content-Disposition'     => 'inline; filename="contract-' . $loanModel->loan_number . '.html"',
            'X-Content-Type-Options'  => 'nosniff',
            'Cache-Control'           => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
        ]);
    }
}