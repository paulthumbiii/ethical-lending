<?php

declare(strict_types=1);

namespace App\Http\Controllers\Borrower;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStatementRequest;
use App\Models\Borrower;
use App\Models\LoanApplication;
use App\Services\Underwriting\StatementIntakeService;
use Illuminate\Http\RedirectResponse;

final class StatementController extends Controller
{
    public function __construct(private readonly StatementIntakeService $intake)
    {
    }

    public function store(StoreStatementRequest $request, LoanApplication $loanApplication): RedirectResponse
    {
        $borrower = auth()->user()?->borrower;

        if (! $borrower instanceof Borrower || (int) $loanApplication->borrower_id !== (int) $borrower->id) {
            abort(404);
        }

        $this->intake->store(
            (int) $borrower->id,
            (int) $loanApplication->id,
            (int) $request->user()->id,
            $request->file('statement'),
            $request->validated('statement_password'),
            (string) ($request->ip() ?? '127.0.0.1'),
            (string) ($request->userAgent() ?? 'unknown')
        );

        return redirect()->route('borrower.apply.create')
            ->with('status', 'Statement received. A credit officer will check it and you will see the result here.');
    }
}