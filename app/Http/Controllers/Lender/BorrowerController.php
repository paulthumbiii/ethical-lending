<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lender;

use App\Enums\StaffPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\BorrowerDirectoryRequest;
use App\Models\AuditTrail;
use App\Models\Borrower;
use App\Models\LoanApplication;
use App\Models\MpesaStatementUpload;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

final class BorrowerController extends Controller
{
    private const TABS = [
        'overview'     => 'Overview',
        'loans'        => 'Loans',
        'applications' => 'Applications',
        'statements'   => 'Statements',
        'activity'     => 'Activity',
    ];

    public function index(BorrowerDirectoryRequest $request): View
    {
        $q = trim((string) $request->validated('q'));
        $status = (string) ($request->validated('status') ?? 'all');

        $borrowers = Borrower::query()
            ->withCount('loans')
            ->when($q !== '', function ($query) use ($q): void {
                $like = '%' . $q . '%';
                $query->where(function ($w) use ($like): void {
                    $w->where('full_name', 'like', $like)
                        ->orWhere('phone_number', 'like', $like)
                        ->orWhere('id_number', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->when($status === 'blacklisted', fn ($query) => $query->where('is_blacklisted', true))
            ->when($status === 'clear', fn ($query) => $query->where('is_blacklisted', false))
            ->orderBy('full_name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return view('lender.borrowers.index', [
            'borrowers' => $borrowers,
            'q'         => $q,
            'status'    => $status,
        ]);
    }

    public function show(Request $request, Borrower $borrower): View
    {
        $tabs = self::TABS;

        // Statement evidence is only for people who decide on it.
        if (! $request->user()->can(StaffPermission::StatementsView->value)) {
            unset($tabs['statements']);
        }

        $tab = (string) $request->query('tab', 'overview');
        if (! array_key_exists($tab, $tabs)) {
            $tab = 'overview';
        }

        $borrower->load('financialProfile');

        /** @var Collection<int, mixed> $rows */
        $rows = match ($tab) {
            'loans' => $borrower->loans()->with('product')->latest('id')->get(),
            'applications' => LoanApplication::query()
                ->where('borrower_id', $borrower->id)
                ->with('product')
                ->latest('id')
                ->get(),
            'statements' => MpesaStatementUpload::query()
                ->where('borrower_id', $borrower->id)
                ->latest('id')
                ->get(),
            'activity' => AuditTrail::query()
                ->where(function ($w) use ($borrower): void {
                    $w->where(function ($own) use ($borrower): void {
                        $own->where('auditable_type', Borrower::class)
                            ->where('auditable_id', $borrower->id);
                    });
                    if ($borrower->user_id !== null) {
                        $w->orWhere('user_id', $borrower->user_id);
                    }
                })
                ->latest('id')
                ->limit(50)
                ->get(),
            default => collect(),
        };

        return view('lender.borrowers.show', [
            'borrower' => $borrower,
            'tabs'     => $tabs,
            'tab'      => $tab,
            'rows'     => $rows,
        ]);
    }
}