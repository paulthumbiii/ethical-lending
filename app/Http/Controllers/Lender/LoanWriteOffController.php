<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lender;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Services\Servicing\LoanWriteOffService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class LoanWriteOffController extends Controller
{
    public function __construct(private readonly LoanWriteOffService $service)
    {
    }

    public function store(Request $request, Loan $loan): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $this->service->propose(
                $loan,
                (int) $request->user()->id,
                $validated['reason']
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['write_off' => $e->getMessage()]);
        }

        return back()->with('status', "Write-off request submitted for Loan #{$loan->loan_number}. It is now awaiting checker review on the Review Desk.");
    }
}