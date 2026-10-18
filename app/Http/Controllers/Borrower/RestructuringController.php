<?php

declare(strict_types=1);

namespace App\Http\Controllers\Borrower;

use App\Exceptions\Fintech\RestructuringNotEligibleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptRestructuringRequest;
use App\Services\Customer\BorrowerPortalService;
use Illuminate\Http\RedirectResponse;

final class RestructuringController extends Controller
{
    public function __construct(private readonly BorrowerPortalService $portal)
    {
    }

    public function accept(AcceptRestructuringRequest $request, int $loan): RedirectResponse
    {
        $borrower = $request->user()?->borrower;
        abort_if($borrower === null, 403);

        $ownLoan = $borrower->loans()->whereKey($loan)->firstOrFail();

        try {
            $this->portal->acceptRelief($ownLoan, (int) $request->validated('tenure_extension_days'));
        } catch (RestructuringNotEligibleException) {
            return back()->withErrors([
                'relief' => 'We cannot offer a relief plan online right now. Please contact support to talk through your options.',
            ]);
        } catch (\DomainException) {
            return back()->withErrors([
                'relief' => 'This relief plan is not available for your loan at the moment.',
            ]);
        }

        return redirect()
            ->route('borrower.dashboard')
            ->with('status', 'Your relief plan is active. Your new payment dates are on your account.');
    }
}