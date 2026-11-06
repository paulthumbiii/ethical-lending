<?php

declare(strict_types=1);

namespace App\Http\Controllers\Borrower;

use App\Enums\LoanStatus;
use App\Http\Controllers\Controller;
use App\Models\BorrowerKycDocument;
use App\Services\Customer\BorrowerPortalService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

final class AccountController extends Controller
{
    public function __construct(private readonly BorrowerPortalService $portal)
    {
    }

    public function show(Request $request): View
    {
        $borrower = $request->user()?->borrower;
        $loans = [];

        /** @var Collection<string, BorrowerKycDocument> $documents */
        $documents = collect();

        if ($borrower !== null) {
            foreach ($borrower->loans()->latest('id')->get() as $loan) {
                $owed = '0.00';
                $open = $loan->status !== LoanStatus::SETTLED && $loan->status !== LoanStatus::WRITTEN_OFF;

                if ($open) {
                    $b = $this->portal->balances($loan);
                    $owed = bcadd(bcadd($b['principal'], $b['interest'], 2), $b['fees'], 2);
                }

                $schedule = [];
                // Instalments replaced by a relief plan are history, not something the borrower should pay.
                $items = $loan->amortizationSchedules()
                    ->where('status', '!=', 'superseded')
                    ->orderBy('installment_number')
                    ->get();

                foreach ($items as $item) {
                    $paid = bcadd(
                        bcadd((string) $item->fees_paid, (string) $item->interest_paid, 2),
                        (string) $item->principal_paid,
                        2
                    );
                    $left = bcsub((string) $item->total_installment_due, $paid, 2);
                    $left = bccomp($left, '0.00', 2) < 0 ? '0.00' : $left;
                    $status = (string) $item->status;

                    // "Overdue" is derived: there is no overdue status on an instalment.
                    $dueDay = CarbonImmutable::parse($item->due_date->toDateString(), 'Africa/Nairobi')->endOfDay();
                    $overdue = $open
                        && $status !== 'paid'
                        && bccomp($left, '0.00', 2) > 0
                        && $dueDay->isPast();

                    $schedule[] = [
                        'number'  => (int) $item->installment_number,
                        'due'     => $item->due_date,
                        'left'    => $left,
                        'status'  => $status,
                        'overdue' => $overdue,
                    ];
                }

                $loans[] = ['loan' => $loan, 'owed' => $owed, 'schedule' => $schedule];
            }

            $documents = $borrower->kycDocuments()->get()->keyBy('document_type');
        }

        return view('borrower.account', [
            'borrower'  => $borrower,
            'loans'     => $loans,
            'documents' => $documents,
        ]);
    }
}