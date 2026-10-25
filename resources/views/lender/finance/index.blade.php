<x-layouts.lender title="Finance">
    @php
        $kes = fn ($v): string => 'KES ' . number_format((float) $v, 2);
        $inputClass = 'rounded border border-line bg-white px-2 py-1 text-sm';
    @endphp

    <div class="space-y-4">
        <x-page-header subtitle="A read-only view of the books. Nothing on this page can post, close a period or remit tax." />

        <x-tabs :tabs="$tabs" :active="$tab" />

        {{-- ================= JOURNAL ================= --}}
        @if ($tab === 'journal')
            <x-filter-bar :action="route('lender.finance.index')">
                <input type="hidden" name="tab" value="journal">
                <label class="text-sm">From
                    <input type="date" name="from" value="{{ $filters['from'] }}" class="{{ $inputClass }}">
                </label>
                <label class="text-sm">To
                    <input type="date" name="to" value="{{ $filters['to'] }}" class="{{ $inputClass }}">
                </label>
                <label class="text-sm">Account
                    <select name="account" class="{{ $inputClass }}">
                        <option value="">All accounts</option>
                        @foreach ($accounts as $a)
                            <option value="{{ $a->account_code }}" @selected($filters['account'] === $a->account_code)>
                                {{ $a->account_code }} · {{ $a->account_name }}
                            </option>
                        @endforeach
                    </select>
                </label>
            </x-filter-bar>

            <x-data-table
                :headings="['Entry', 'Posted', 'Description', 'Lines', 'Amount']"
                :is-empty="$entries->isEmpty()"
                empty-title="No journal entries"
                empty-text="Nothing matches these filters, or nothing has been posted yet."
            >
                @foreach ($entries as $e)
                    @php
                        $total = '0.00';
                        foreach ($e->items as $i) {
                            $total = bcadd($total, (string) $i->debit, 2);
                        }
                    @endphp
                    <tr class="align-top">
                        <td class="px-4 py-3 font-medium">{{ $e->entry_number }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $e->posted_at?->format('d M Y H:i') }}</td>
                        <td class="px-4 py-3">{{ $e->description }}</td>
                        <td class="px-4 py-3">
                            @foreach ($e->items as $i)
                                <div class="text-xs tabular-nums">
                                    {{ $i->account?->account_code }} · {{ $i->account?->account_name }}
                                    @if (bccomp((string) $i->debit, '0.00', 2) > 0)
                                        <span class="font-medium">Dr {{ number_format((float) $i->debit, 2) }}</span>
                                    @else
                                        <span class="font-medium">Cr {{ number_format((float) $i->credit, 2) }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </td>
                        <td class="px-4 py-3 tabular-nums whitespace-nowrap">{{ $kes($total) }}</td>
                    </tr>
                @endforeach
            </x-data-table>

            <x-pager :paginator="$entries" />
        @endif

        {{-- ================= TRIAL BALANCE ================= --}}
        @if ($tab === 'trial')
            <x-filter-bar :action="route('lender.finance.index')">
                <input type="hidden" name="tab" value="trial">
                <label class="text-sm">From
                    <input type="date" name="from" value="{{ $filters['from'] }}" class="{{ $inputClass }}">
                </label>
                <label class="text-sm">To
                    <input type="date" name="to" value="{{ $filters['to'] }}" class="{{ $inputClass }}">
                </label>
            </x-filter-bar>

            @if (count($trialRows) > 0)
                <div class="flex items-center gap-2 text-sm">
                    @if ($isBalanced)
                        <x-badge tone="success">Balanced</x-badge>
                        <span>Total debits equal total credits.</span>
                    @else
                        <x-badge tone="danger">Out of balance</x-badge>
                        <span>Total debits and credits differ. Investigate before relying on these figures.</span>
                    @endif
                </div>
            @endif

            <x-data-table
                :headings="['Code', 'Account', 'Type', 'Debits', 'Credits']"
                :is-empty="count($trialRows) === 0"
                empty-title="Nothing posted"
                empty-text="No journal lines fall in this date range."
            >
                @foreach ($trialRows as $r)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $r['code'] }}</td>
                        <td class="px-4 py-3">{{ $r['name'] }}</td>
                        <td class="px-4 py-3">{{ ucwords(strtolower($r['type'])) }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ $kes($r['debit']) }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ $kes($r['credit']) }}</td>
                    </tr>
                @endforeach
                <tr class="font-semibold">
                    <td class="px-4 py-3" colspan="3">Totals</td>
                    <td class="px-4 py-3 tabular-nums">{{ $kes($grandDebit) }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ $kes($grandCredit) }}</td>
                </tr>
            </x-data-table>
        @endif

        {{-- ================= EXCISE ================= --}}
        @if ($tab === 'excise')
            <div class="grid gap-4 sm:grid-cols-3">
                <x-card title="Owed to KRA (accrued)">
                    <p class="text-xl font-semibold tabular-nums">{{ $kes($accrued) }}</p>
                </x-card>
                <x-card title="Already remitted">
                    <p class="text-xl font-semibold tabular-nums">{{ $kes($remitted) }}</p>
                </x-card>
                <x-card title="Ledger vs general ledger">
                    <p class="text-sm tabular-nums">Excise ledger: {{ $kes($recorded) }}</p>
                    <p class="text-sm tabular-nums">GL credits to 2030: {{ $kes($glCredit) }}</p>
                    <p class="mt-1">
                        @if ($tiesOut)
                            <x-badge tone="success">Ties out</x-badge>
                        @else
                            <x-badge tone="warning">Does not tie out</x-badge>
                        @endif
                    </p>
                </x-card>
            </div>

            <x-filter-bar :action="route('lender.finance.index')">
                <input type="hidden" name="tab" value="excise">
                <label class="text-sm">Status
                    <select name="status" class="{{ $inputClass }}">
                        <option value="all" @selected($filters['status'] === 'all')>All</option>
                        <option value="accrued" @selected($filters['status'] === 'accrued')>Accrued</option>
                        <option value="remitted" @selected($filters['status'] === 'remitted')>Remitted</option>
                    </select>
                </label>
            </x-filter-bar>

            <x-data-table
                :headings="['Loan', 'Fee', 'Fee charged', 'Rate', 'Excise', 'Status', 'Remitted']"
                :is-empty="$exciseRows->isEmpty()"
                empty-title="No excise records"
                empty-text="Excise is recorded when a fee with excise duty is charged on a loan."
            >
                @foreach ($exciseRows as $r)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $r->loan_number ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $r->fee_name }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ $kes($r->fee_charged) }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ number_format((float) $r->excise_rate, 2) }}%</td>
                        <td class="px-4 py-3 tabular-nums">{{ $kes($r->excise_duty_amount) }}</td>
                        <td class="px-4 py-3">
                            @if ($r->remittance_status === 'remitted')
                                <x-badge tone="success">Remitted</x-badge>
                            @else
                                <x-badge tone="warning">{{ ucfirst((string) $r->remittance_status) }}</x-badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $r->remitted_at ? \Illuminate\Support\Carbon::parse($r->remitted_at)->format('d M Y') : '—' }}</td>
                    </tr>
                @endforeach
            </x-data-table>

            <x-pager :paginator="$exciseRows" />
        @endif

        {{-- ================= M-PESA ================= --}}
        @if ($tab === 'mpesa')
            <x-tabs :tabs="$sources" :active="$source" param="source" />

            @if ($source === 'c2b')
                <x-data-table
                    :headings="['Receipt', 'Received', 'Amount', 'Account ref', 'Phone', 'Matched']"
                    :is-empty="$mpesaRows->isEmpty()"
                    empty-title="No paybill payments"
                    empty-text="Payments arrive here when Safaricom confirms a paybill transaction."
                >
                    @foreach ($mpesaRows as $r)
                        <tr>
                            <td class="px-4 py-3 font-medium">{{ $r->trans_id }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $r->created_at?->format('d M Y H:i') }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ $kes($r->trans_amount) }}</td>
                            <td class="px-4 py-3">{{ $r->bill_ref_number }}</td>
                            <td class="px-4 py-3">{{ $r->msisdn }}</td>
                            <td class="px-4 py-3">
                                @if ($r->is_reconciled)
                                    <x-badge tone="success">Reconciled</x-badge>
                                @else
                                    <x-badge tone="warning">Unreconciled</x-badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>
            @elseif ($source === 'stk')
                <x-data-table
                    :headings="['Requested', 'Loan', 'Phone', 'Amount', 'Receipt', 'Status', 'Result']"
                    :is-empty="$mpesaRows->isEmpty()"
                    empty-title="No STK requests"
                    empty-text="Requests appear here when a borrower presses Pay."
                >
                    @foreach ($mpesaRows as $r)
                        <tr>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $r->created_at?->format('d M Y H:i') }}</td>
                            <td class="px-4 py-3 font-medium">{{ $r->loan?->loan_number ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $r->phone_number }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ $kes($r->amount) }}</td>
                            <td class="px-4 py-3">{{ $r->mpesa_receipt_number ?? '—' }}</td>
                            <td class="px-4 py-3"><x-status-badge :status="(string) $r->status" /></td>
                            <td class="px-4 py-3">{{ $r->result_desc ?? '—' }}</td>
                        </tr>
                    @endforeach
                </x-data-table>
            @else
                <x-data-table
                    :headings="['Loan', 'Recipient', 'Amount', 'Status', 'Transaction', 'Result', 'Paid out']"
                    :is-empty="$mpesaRows->isEmpty()"
                    empty-title="No payouts"
                    empty-text="Payouts appear here once money out has been started for a loan."
                >
                    @foreach ($mpesaRows as $r)
                        <tr>
                            <td class="px-4 py-3 font-medium">{{ $r->loan_number ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $r->recipient_phone }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ $kes($r->amount) }}</td>
                            <td class="px-4 py-3"><x-status-badge :status="(string) $r->status" /></td>
                            <td class="px-4 py-3">{{ $r->transaction_id ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $r->response_description ?? '—' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $r->disbursed_at ? \Illuminate\Support\Carbon::parse($r->disbursed_at)->format('d M Y H:i') : '—' }}</td>
                        </tr>
                    @endforeach
                </x-data-table>
            @endif

            <x-pager :paginator="$mpesaRows" />
        @endif
    </div>
</x-layouts.lender>