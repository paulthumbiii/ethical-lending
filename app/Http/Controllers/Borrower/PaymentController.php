<?php

declare(strict_types=1);

namespace App\Http\Controllers\Borrower;

use App\Enums\LoanStatus;
use App\Exceptions\Fintech\StkPushFailedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StkPaymentRequest;
use App\Services\Customer\BorrowerPortalService;
use App\Services\Financial\Mpesa\StkPushService;
use Illuminate\Http\RedirectResponse;

final class PaymentController extends Controller
{
    public function __construct(
        private readonly BorrowerPortalService $portal,
        private readonly StkPushService $stk,
    ) {
    }

    public function store(StkPaymentRequest $request, int $loan): RedirectResponse
    {
        $borrower = $request->user()?->borrower;
        abort_if($borrower === null, 403);

        $ownLoan = $borrower->loans()->whereKey($loan)->firstOrFail();

        $payable = [LoanStatus::DISBURSED, LoanStatus::DEFAULTED, LoanStatus::RESTRUCTURED];
        if (! in_array($ownLoan->status, $payable, true)) {
            return $this->fail('This loan cannot take payments right now.');
        }

        $balances = $this->portal->balances($ownLoan);
        $owed = bcadd(bcadd($balances['principal'], $balances['interest'], 2), $balances['fees'], 2);
        $ceiling = (int) bcadd($owed, '0.99', 0); // rounded up to whole shillings, as STK requires

        $amount = (int) $request->validated('amount');

        if ($ceiling < 1) {
            return $this->fail('Nothing is due on this loan right now.');
        }

        if ($amount > $ceiling) {
            return $this->fail('That is more than you owe. The most you can pay is KES ' . number_format($ceiling) . '.');
        }

        try {
            $this->stk->initiate($ownLoan, $amount);
        } catch (\DomainException | StkPushFailedException $e) {
            return $this->fail($e->getMessage());
        }

        return redirect()
            ->route('borrower.dashboard')
            ->with('status', 'Check your phone and enter your M-Pesa PIN to finish. Your balance updates once M-Pesa confirms the payment.');
    }

    private function fail(string $message): RedirectResponse
    {
        return back()->withErrors(['payment' => $message]);
    }
}