<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lender;

use App\Exceptions\Fintech\SimSwapRiskDetectedException;
use App\Http\Controllers\Controller;
use App\Models\LoanApplication;
use App\Services\Lending\LoanDisbursementWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

final class LoanDisbursementController extends Controller
{
    public function __construct(private readonly LoanDisbursementWorkflowService $service)
    {
    }

    public function store(Request $request, LoanApplication $loanApplication): RedirectResponse
    {
        $target = redirect()->route('lender.applications.show', ['loanApplication' => $loanApplication->id]);

        try {
            $this->service->disburse(
                $loanApplication->id,
                (int) $request->user()->id,
                (string) ($request->ip() ?? '127.0.0.1'),
                (string) ($request->userAgent() ?? 'unknown')
            );
        } catch (SimSwapRiskDetectedException) {
            return $target->withErrors([
                'review' => 'This phone number changed SIM recently, so the payout is blocked for the borrower\'s safety. Nothing was sent.',
            ]);
        } catch (RuntimeException $e) {
            return $target->withErrors(['review' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);

            return $target->withErrors([
                'review' => 'The payout could not be started. Nothing was sent. Check the log and try again.',
            ]);
        }

        return $target->with('status', 'Loan created and payout requested. We are waiting for M-Pesa to confirm.');
    }

    public function simulate(Request $request, LoanApplication $loanApplication): RedirectResponse
    {
        $target = redirect()->route('lender.applications.show', ['loanApplication' => $loanApplication->id]);

        try {
            $this->service->simulateResult(
                $loanApplication->id,
                (int) $request->user()->id,
                (string) ($request->ip() ?? '127.0.0.1'),
                (string) ($request->userAgent() ?? 'unknown')
            );
        } catch (RuntimeException $e) {
            return $target->withErrors(['review' => $e->getMessage()]);
        }

        return $target->with('status', 'Simulated M-Pesa result applied. The loan is now disbursed.');
    }
}