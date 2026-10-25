<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lender;

use App\Enums\StaffPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoanDirectoryRequest;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanLedgerEntry;
use App\Models\MpesaB2cDisbursement;
use App\Models\TaxWithholdingLedger;
use App\Services\Customer\BorrowerPortalService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class LoanController extends Controller
{
    private const TABS = [
        'overview' => 'Overview',
        'ledger'   => 'Ledger',
        'payments' => 'Payments',
        'fees'     => 'Fees',
        'journal'  => 'Journal',
        'schedule' => 'Schedule',
    ];

    public function __construct(private readonly BorrowerPortalService $portal)
    {
    }

    public function index(LoanDirectoryRequest $request): View
    {
        $q = trim((string) $request->validated('q'));
        $status = (string) ($request->validated('status') ?? '');
        $frozen = $request->validated('frozen') === '1';

        $loans = Loan::query()
            ->with(['borrower', 'product'])
            ->when($q !== '', function ($query) use ($q): void {
                $like = '%' . $q . '%';
                $query->where(function ($w) use ($like): void {
                    $w->where('loan_number', 'like', $like)
                        ->orWhereHas('borrower', function ($b) use ($like): void {
                            $b->where('full_name', 'like', $like)
                                ->orWhere('phone_number', 'like', $like);
                        });
                });
            })
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($frozen, fn ($query) => $query->where('is_in_duplum_locked', true))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        // Latest ledger row per loan on this page, in two queries (no per-row lookups).
        $latestIds = LoanLedgerEntry::query()
            ->selectRaw('MAX(id) as id')
            ->whereIn('loan_id', $loans->pluck('id')->all())
            ->groupBy('loan_id')
            ->pluck('id')
            ->all();

        $balances = LoanLedgerEntry::query()
            ->whereIn('id', $latestIds)
            ->pluck('total_running_balance', 'loan_id');

        return view('lender.loans.index', [
            'loans'    => $loans,
            'balances' => $balances,
            'q'        => $q,
            'status'   => $status,
            'frozen'   => $frozen,
        ]);
    }

    public function show(Request $request, Loan $loan): View
    {
        $user = $request->user();
        $tabs = self::TABS;

        if (! $user->can(StaffPermission::LedgerView->value)) {
            unset($tabs['ledger'], $tabs['fees']);
        }

        if (! $user->can(StaffPermission::FinanceView->value)) {
            unset($tabs['journal']);
        }

        $tab = (string) $request->query('tab', 'overview');
        if (! array_key_exists($tab, $tabs)) {
            $tab = 'overview';
        }

        $loan->load(['borrower', 'product']);

        $overview = null;
        $payouts = collect();

        if ($tab === 'overview') {
            $balances = $this->portal->balances($loan);

            $overview = [
                'balances'  => $balances,
                'owed'      => bcadd(bcadd($balances['principal'], $balances['interest'], 2), $balances['fees'], 2),
                'meter'     => $this->portal->inDuplumMeter($loan->load('inDuplumTracking')),
                'proposals' => $loan->restructuringProposals()->latest('id')->limit(5)->get(),
                'payout'    => MpesaB2cDisbursement::query()->where('loan_id', $loan->id)->latest('id')->first(),
            ];
        }

        if ($tab === 'payments') {
            $payouts = MpesaB2cDisbursement::query()->where('loan_id', $loan->id)->latest('id')->get();
        }

        /** @var Collection<int, mixed> $rows */
        $rows = match ($tab) {
            'ledger'   => $loan->ledgerEntries()->orderBy('id')->get(),
            'payments' => $loan->waterfallLogs()->latest('id')->get(),
            'fees'     => TaxWithholdingLedger::query()->where('loan_id', $loan->id)->orderBy('id')->get(),
            'journal'  => JournalEntry::query()
                ->where('reference_type', Loan::class)
                ->where('reference_id', $loan->id)
                ->with('items.account')
                ->orderBy('id')
                ->get(),
            'schedule' => $loan->amortizationSchedules()->orderBy('installment_number')->get(),
            default    => collect(),
        };

        return view('lender.loans.show', [
            'loan'     => $loan,
            'tabs'     => $tabs,
            'tab'      => $tab,
            'rows'     => $rows,
            'overview' => $overview,
            'payouts'  => $payouts,
        ]);
    }
}