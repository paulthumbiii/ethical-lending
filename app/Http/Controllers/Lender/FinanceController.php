<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lender;

use App\Http\Controllers\Controller;
use App\Http\Requests\FinanceViewRequest;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\MpesaB2cDisbursement;
use App\Models\MpesaC2bCallback;
use App\Models\MpesaStkRequest;
use App\Models\TaxWithholdingLedger;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

/**
 * Read-only finance views: journal, trial balance, excise payable, M-Pesa.
 * Gated by the finance.view permission on the route.
 */
final class FinanceController extends Controller
{
    public function index(FinanceViewRequest $request): View
    {
        $tab = $request->tab();

        $data = match ($tab) {
            'trial'  => $this->trialBalance($request),
            'excise' => $this->excise($request),
            'mpesa'  => $this->mpesa($request),
            default  => $this->journal($request),
        };

        return view('lender.finance.index', array_merge($data, [
            'tab'      => $tab,
            'tabs'     => [
                'journal' => 'Journal',
                'trial'   => 'Trial balance',
                'excise'  => 'Excise payable',
                'mpesa'   => 'M-Pesa',
            ],
            'sources'  => ['c2b' => 'Paybill (C2B)', 'stk' => 'STK requests', 'b2c' => 'Payouts (B2C)'],
            'accounts' => ChartOfAccount::query()->orderBy('account_code')->get(['account_code', 'account_name']),
            'filters'  => [
                'from'    => $request->validated('from'),
                'to'      => $request->validated('to'),
                'account' => $request->validated('account'),
                'status'  => $request->validated('status') ?? 'all',
            ],
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function journal(FinanceViewRequest $request): array
    {
        $from    = $request->validated('from');
        $to      = $request->validated('to');
        $account = $request->validated('account');

        $entries = JournalEntry::query()
            ->with('items.account')
            ->when($from, fn ($q) => $q->whereDate('posted_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('posted_at', '<=', $to))
            ->when($account, fn ($q) => $q->whereHas(
                'items.account',
                fn ($a) => $a->where('account_code', $account)
            ))
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return ['entries' => $entries];
    }

    /**
     * @return array<string, mixed>
     */
    private function trialBalance(FinanceViewRequest $request): array
    {
        $from = $request->validated('from');
        $to   = $request->validated('to');

        $raw = DB::table('journal_items as i')
            ->join('journal_entries as e', 'e.id', '=', 'i.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'i.chart_of_account_id')
            ->when($from, fn ($q) => $q->whereDate('e.posted_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('e.posted_at', '<=', $to))
            ->groupBy('a.id', 'a.account_code', 'a.account_name', 'a.account_type')
            ->orderBy('a.account_code')
            ->selectRaw('a.account_code, a.account_name, a.account_type, SUM(i.debit) as total_debit, SUM(i.credit) as total_credit')
            ->get();

        $rows        = [];
        $grandDebit  = '0.00';
        $grandCredit = '0.00';

        foreach ($raw as $r) {
            $debit  = $this->money($r->total_debit);
            $credit = $this->money($r->total_credit);

            $rows[] = [
                'code'   => $r->account_code,
                'name'   => $r->account_name,
                'type'   => $r->account_type,
                'debit'  => $debit,
                'credit' => $credit,
            ];

            $grandDebit  = bcadd($grandDebit, $debit, 2);
            $grandCredit = bcadd($grandCredit, $credit, 2);
        }

        return [
            'trialRows'   => $rows,
            'grandDebit'  => $grandDebit,
            'grandCredit' => $grandCredit,
            'isBalanced'  => bccomp($grandDebit, $grandCredit, 2) === 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function excise(FinanceViewRequest $request): array
    {
        $status = (string) ($request->validated('status') ?? 'all');

        $rows = TaxWithholdingLedger::query()
            ->leftJoin('loans', 'loans.id', '=', 'tax_withholding_ledgers.loan_id')
            ->select('tax_withholding_ledgers.*', 'loans.loan_number')
            ->when($status !== 'all', fn ($q) => $q->where('tax_withholding_ledgers.remittance_status', $status))
            ->orderByDesc('tax_withholding_ledgers.id')
            ->paginate(25)
            ->withQueryString();

        $sums = DB::table('tax_withholding_ledgers')
            ->selectRaw('remittance_status, SUM(excise_duty_amount) as total')
            ->groupBy('remittance_status')
            ->pluck('total', 'remittance_status');

        $accrued  = $this->money($sums['accrued'] ?? 0);
        $remitted = $this->money($sums['remitted'] ?? 0);

        // Tie-out: everything recorded in the excise ledger should equal what the GL
        // has credited to account 2030 (KRA Excise Duty Payable).
        $gl = DB::table('journal_items as i')
            ->join('chart_of_accounts as a', 'a.id', '=', 'i.chart_of_account_id')
            ->where('a.account_code', '2030')
            ->selectRaw('SUM(i.credit) as total_credit')
            ->first();

        $recorded = bcadd($accrued, $remitted, 2);
        $glCredit = $this->money($gl->total_credit ?? 0);

        return [
            'exciseRows' => $rows,
            'accrued'    => $accrued,
            'remitted'   => $remitted,
            'recorded'   => $recorded,
            'glCredit'   => $glCredit,
            'tiesOut'    => bccomp($recorded, $glCredit, 2) === 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mpesa(FinanceViewRequest $request): array
    {
        $source = (string) ($request->validated('source') ?? 'c2b');

        $rows = match ($source) {
            'stk' => MpesaStkRequest::query()
                ->with('loan:id,loan_number')
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString(),
            'b2c' => MpesaB2cDisbursement::query()
                ->leftJoin('loans', 'loans.id', '=', 'mpesa_b2c_disbursements.loan_id')
                ->select('mpesa_b2c_disbursements.*', 'loans.loan_number')
                ->orderByDesc('mpesa_b2c_disbursements.id')
                ->paginate(25)
                ->withQueryString(),
            default => MpesaC2bCallback::query()
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString(),
        };

        return ['source' => $source, 'mpesaRows' => $rows];
    }

    /**
     * Format a database SUM as a 2-decimal string (display normalisation only).
     */
    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}