<?php

declare(strict_types=1);

namespace App\Http\Controllers\Borrower;

use App\Enums\LoanStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBorrowerPromiseRequest;
use App\Models\CollectionCase;
use App\Models\Loan;
use App\Services\Customer\BorrowerPortalService;
use App\Services\Servicing\OverdueDetectionService;
use App\Services\Servicing\PromiseToPayService;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

final class PromiseController extends Controller
{
    public function store(
        StoreBorrowerPromiseRequest $request,
        Loan $loan,
        OverdueDetectionService $detector,
        PromiseToPayService $promises,
        BorrowerPortalService $portal
    ): RedirectResponse {
        $user = $request->user();
        $borrower = $user?->borrower;
        abort_if($user === null || $borrower === null, 404);

        // Only the borrower's own loans. Anything else is a 404.
        $own = $borrower->loans()->whereKey($loan->id)->firstOrFail();

        $back = redirect()->route('borrower.dashboard');
        $fail = fn (string $message): RedirectResponse => $back->withInput()->withErrors(['promise' => $message]);

        $statuses = [LoanStatus::DISBURSED, LoanStatus::RESTRUCTURED, LoanStatus::DEFAULTED];

        if (! in_array($own->status, $statuses, true) || $detector->daysPastDue($own) < 1) {
            return $fail('Your loan is not overdue, so there is nothing to promise yet.');
        }

        $balances = $portal->balances($own);
        $owed = bcadd(bcadd($balances['principal'], $balances['interest'], 2), $balances['fees'], 2);
        $amount = bcadd((string) $request->validated('amount'), '0', 2);

        if (bccomp($amount, $owed, 2) > 0) {
            return $fail('The amount cannot be more than you owe, KES ' . number_format((float) $owed, 2) . '.');
        }

        $case = CollectionCase::query()->where('loan_id', $own->id)->first();

        if ($case === null) {
            $detector->processLoan($own);
            $case = CollectionCase::query()->where('loan_id', $own->id)->first();
        }

        if ($case === null) {
            return $fail('We could not save your promise just now. Please try again later.');
        }

        try {
            $promise = $promises->record(
                $case,
                $amount,
                (string) $request->validated('promised_date'),
                $user->id
            );
        } catch (RuntimeException $e) {
            return $fail($e->getMessage());
        }

        return $back->with('status', sprintf(
            'Thank you. You promised KES %s by %s. We will not send reminders until then.',
            number_format((float) $promise->promised_amount, 2),
            $promise->promised_date->format('j M Y')
        ));
    }
}