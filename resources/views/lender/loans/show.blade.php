@php
    $money = fn ($v): string => 'KES ' . number_format((float) $v, 2);
    $date = fn ($v): string => $v ? \Carbon\CarbonImmutable::parse($v)->format('j M Y') : '-';
    $stamp = fn ($v): string => $v ? \Carbon\CarbonImmutable::parse($v)->format('j M Y H:i') : '-';
    $meterLabels = ['not_triggered' => 'Not triggered', 'accruing' => 'Charges counting', 'frozen' => 'Charges stopped'];
@endphp
<x-layouts.lender :title="$loan->loan_number">
    <x-page-header :subtitle="($loan->product?->name ?? 'Loan') . ' · ' . number_format((float) $loan->annual_interest_rate, 2) . '% a year · ' . $loan->tenure_days . ' days'">
        <x-slot:actions>
            <x-status-badge :status="$loan->status->value" />
            @if ($loan->is_in_duplum_locked)
                <x-badge tone="danger">Frozen</x-badge>
            @endif
            @if ($loan->borrower)
                @can(\App\Enums\StaffPermission::BorrowersView->value)
                    <a href="{{ route('lender.borrowers.show', $loan->borrower) }}" class="text-sm font-medium text-brand-700 underline-offset-4 hover:underline">{{ $loan->borrower->full_name }}</a>
                @endcan
            @endif
            <a href="{{ route('lender.loans.index') }}" class="text-sm font-medium text-muted underline-offset-4 hover:text-ink hover:underline">Back to loans</a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-brand-500 bg-brand-50 px-4 py-3 text-sm text-brand-900" role="status">{{ session('status') }}</div>
    @endif

    @error('write_off')
        <div class="mb-4 rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{{ $message }}</div>
    @enderror

    <x-tabs :tabs="$tabs" :active="$tab" />

    @if ($tab === 'overview')
        @php $meter = $overview['meter']; $payout = $overview['payout']; @endphp
        <div class="grid gap-4 lg:grid-cols-2">
            <x-card title="What is owed">
                <dl class="divide-y divide-line text-sm">
                    <div class="flex justify-between py-2"><dt class="text-muted">Principal</dt><dd class="tabular-nums">{{ $money($overview['balances']['principal']) }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-muted">Interest</dt><dd class="tabular-nums">{{ $money($overview['balances']['interest']) }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-muted">Penalties and fees</dt><dd class="tabular-nums">{{ $money($overview['balances']['fees']) }}</dd></div>
                    <div class="flex justify-between py-2 font-semibold"><dt>Total owed</dt><dd class="tabular-nums">{{ $money($overview['owed']) }}</dd></div>
                </dl>
            </x-card>

            <x-card title="Terms">
                <dl class="divide-y divide-line text-sm">
                    <div class="flex justify-between py-2"><dt class="text-muted">Principal disbursed</dt><dd class="tabular-nums">{{ $money($loan->principal_disbursed) }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-muted">Disbursed</dt><dd>{{ $date($loan->disbursed_at) }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-muted">Due date</dt><dd>{{ $date($loan->due_date) }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-muted">Defaulted</dt><dd>{{ $date($loan->defaulted_at) }}</dd></div>
                </dl>
            </x-card>

            <x-card title="Section 44A in-duplum limit">
                <dl class="divide-y divide-line text-sm">
                    <div class="flex justify-between py-2"><dt class="text-muted">State</dt><dd>{{ $meterLabels[$meter['state']] ?? $meter['state'] }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-muted">Charged since default</dt><dd class="tabular-nums">{{ $money($meter['accrued']) }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-muted">Legal cap</dt><dd class="tabular-nums">{{ $money($meter['cap']) }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-muted">Room left</dt><dd class="tabular-nums">{{ $money($meter['headroom']) }}</dd></div>
                </dl>
            </x-card>

            <x-card title="Money out">
                @if ($payout === null)
                    <p class="text-sm text-muted">No payout has been sent for this loan yet.</p>
                @else
                    <dl class="divide-y divide-line text-sm">
                        <div class="flex items-center justify-between py-2"><dt class="text-muted">Status</dt><dd><x-status-badge :status="$payout->status" /></dd></div>
                        <div class="flex justify-between py-2"><dt class="text-muted">Amount</dt><dd class="tabular-nums">{{ $money($payout->amount) }}</dd></div>
                        <div class="flex justify-between py-2"><dt class="text-muted">Sent to</dt><dd class="tabular-nums">{{ $payout->recipient_phone }}</dd></div>
                        <div class="flex justify-between py-2"><dt class="text-muted">M-Pesa receipt</dt><dd>{{ $payout->transaction_id ?: '-' }}</dd></div>
                        <div class="flex justify-between py-2"><dt class="text-muted">Delivered</dt><dd>{{ $stamp($payout->disbursed_at) }}</dd></div>
                    </dl>
                @endif
            </x-card>

            <x-card title="Restructuring" class="lg:col-span-2">
                @if ($overview['proposals']->isEmpty())
                    <p class="text-sm text-muted">This loan has not been restructured.</p>
                @else
                    <ul class="divide-y divide-line text-sm">
                        @foreach ($overview['proposals'] as $proposal)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span class="font-medium">{{ $proposal->proposal_code }}</span>
                                <span class="tabular-nums text-muted">Penalties forgiven {{ $money($proposal->forgiven_penalties) }} · {{ $proposal->tenure_extension_days }} extra days</span>
                                <x-status-badge :status="$proposal->status" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>

            @if ($loan->status->value === 'written_off')
                <x-card title="Write-off details" class="lg:col-span-2">
                    @php $wo = $loan->writeOff; @endphp
                    @if ($wo)
                        <dl class="grid grid-cols-2 gap-4 text-sm lg:grid-cols-4">
                            <div><dt class="text-xs text-muted">Written off on</dt><dd class="font-medium tabular-nums">{{ $date($wo->written_off_at) }}</dd></div>
                            <div><dt class="text-xs text-muted">Total written off</dt><dd class="font-medium tabular-nums text-rose-700">{{ $money($wo->total_written_off) }}</dd></div>
                            <div><dt class="text-xs text-muted">Approved by</dt><dd class="font-medium">{{ $wo->approver?->name ?? 'Staff' }}</dd></div>
                            <div><dt class="text-xs text-muted">Reason</dt><dd class="font-medium">{{ $wo->write_off_reason }}</dd></div>
                        </dl>
                        <div class="mt-3 border-t border-line pt-2 text-xs text-muted">
                            Principal: {{ $money($wo->written_off_principal) }} &bull; Interest: {{ $money($wo->written_off_interest) }} &bull; Fees: {{ $money($wo->written_off_fees) }}
                        </div>
                    @else
                        <p class="text-sm text-muted">This loan is marked written off.</p>
                    @endif
                </x-card>
            @elseif ($loan->status->value === 'defaulted')
                @php
                    $pendingWo = \App\Models\MakerCheckerRequest::query()
                        ->where('action_type', 'WRITE_OFF_LOAN')
                        ->where('target_entity_type', \App\Models\Loan::class)
                        ->where('target_entity_id', $loan->id)
                        ->where('status', 'pending')
                        ->first();
                @endphp
                <x-card title="Loan write-off" class="lg:col-span-2">
                    @if ($pendingWo)
                        <div class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                            <p class="font-medium">Write-off request #{{ $pendingWo->id }} is pending checker review on the Review Desk.</p>
                            <p class="mt-1 text-xs text-amber-700">Proposed by {{ $pendingWo->maker?->name ?? 'Staff' }} with reason: "{{ $pendingWo->payload['reason'] ?? '' }}". Escalation pauses until approved or rejected.</p>
                        </div>
                    @else
                        <p class="text-sm text-muted">When a defaulted loan is unrecoverable, an authorized staff member can propose a write-off. Dual-control governance requires a separate checker on the Review Desk to approve before debts are cleared from the balance sheet.</p>
                        <form method="POST" action="{{ route('lender.loans.write-off', $loan) }}" class="mt-4 space-y-3"
                              x-on:submit="if (! confirm('Submit this loan for write-off review? A checker must review and approve it on the Review Desk.')) $event.preventDefault()">
                            @csrf
                            <div>
                                <label for="wo-reason" class="block text-xs font-medium text-muted">Reason for write-off</label>
                                <input id="wo-reason" name="reason" type="text" placeholder="e.g. Unrecoverable after Section 44A cap reached; borrower untraceable" required
                                       class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm focus:border-brand-600 focus:outline-none">
                            </div>
                            <div class="flex justify-end">
                                <x-button type="submit" variant="secondary">Submit write-off request</x-button>
                            </div>
                        </form>
                    @endif
                </x-card>
            @endif
        </div>
    @elseif ($tab === 'ledger')
        <x-data-table :headings="['When', 'Type', 'Debit', 'Credit', 'Principal', 'Interest', 'Fees', 'Total', 'Reference']"
                      :is-empty="$rows->isEmpty()"
                      empty-title="No ledger rows yet"
                      empty-text="Rows appear here once money moves on this loan.">
            @foreach ($rows as $entry)
                <tr>
                    <td class="px-4 py-3 whitespace-nowrap">{{ $stamp($entry->created_at) }}</td>
                    <td class="px-4 py-3 font-medium">{{ $entry->entry_type }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ number_format((float) $entry->debit, 2) }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ number_format((float) $entry->credit, 2) }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ number_format((float) $entry->running_principal_balance, 2) }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ number_format((float) $entry->running_interest_balance, 2) }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ number_format((float) $entry->running_fees_balance, 2) }}</td>
                    <td class="px-4 py-3 tabular-nums font-medium">{{ number_format((float) $entry->total_running_balance, 2) }}</td>
                    <td class="px-4 py-3">{{ $entry->reference ?? '-' }}</td>
                </tr>
            @endforeach
        </x-data-table>
    @elseif ($tab === 'payments')
        <h2 class="mb-2 text-sm font-semibold">Repayments received</h2>
        <x-data-table :headings="['When', 'Reference', 'Received', 'To fees', 'To interest', 'To principal', 'Overpaid']"
                      :is-empty="$rows->isEmpty()"
                      empty-title="No repayments yet"
                      empty-text="Each repayment shows how it was split between fees, interest and principal.">
            @foreach ($rows as $log)
                <tr>
                    <td class="px-4 py-3 whitespace-nowrap">{{ $stamp($log->created_at) }}</td>
                    <td class="px-4 py-3 font-medium">{{ $log->payment_reference }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ $money($log->total_amount_received) }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ $money($log->allocated_to_fees) }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ $money($log->allocated_to_interest) }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ $money($log->allocated_to_principal) }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ $money($log->overpayment_amount) }}</td>
                </tr>
            @endforeach
        </x-data-table>

        <h2 class="mb-2 mt-6 text-sm font-semibold">M-Pesa payouts</h2>
        <x-data-table :headings="['Status', 'Amount', 'Sent to', 'Receipt', 'Delivered']"
                      :is-empty="$payouts->isEmpty()"
                      empty-title="No payouts yet"
                      empty-text="A payout record appears once money is sent.">
            @foreach ($payouts as $payout)
                <tr>
                    <td class="px-4 py-3"><x-status-badge :status="$payout->status" /></td>
                    <td class="px-4 py-3 tabular-nums">{{ $money($payout->amount) }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ $payout->recipient_phone }}</td>
                    <td class="px-4 py-3">{{ $payout->transaction_id ?: '-' }}</td>
                    <td class="px-4 py-3">{{ $stamp($payout->disbursed_at) }}</td>
                </tr>
            @endforeach
        </x-data-table>
    @elseif ($tab === 'fees')
        <x-data-table :headings="['Fee', 'Charged', 'Excise rate', 'Excise amount', 'Remittance']"
                      :is-empty="$rows->isEmpty()"
                      empty-title="No fees recorded"
                      empty-text="Fees and the excise duty split appear here once charged.">
            @foreach ($rows as $fee)
                <tr>
                    <td class="px-4 py-3 font-medium">{{ $fee->fee_name }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ $money($fee->fee_charged) }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ number_format((float) $fee->excise_rate, 2) }}%</td>
                    <td class="px-4 py-3 tabular-nums">{{ $money($fee->excise_duty_amount) }}</td>
                    <td class="px-4 py-3"><x-status-badge :status="$fee->remittance_status" /></td>
                </tr>
            @endforeach
        </x-data-table>
    @elseif ($tab === 'journal')
        @forelse ($rows as $entry)
            <x-card :title="$entry->entry_number . ' · ' . $entry->description" class="mb-4">
                <p class="mb-3 text-xs text-muted">Posted {{ $stamp($entry->posted_at) }}</p>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-muted">
                                <th class="py-1 pr-4">Account</th><th class="py-1 pr-4">Debit</th><th class="py-1">Credit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($entry->items as $item)
                                <tr>
                                    <td class="py-2 pr-4">{{ $item->account?->account_code }} {{ $item->account?->account_name }}</td>
                                    <td class="py-2 pr-4 tabular-nums">{{ number_format((float) $item->debit, 2) }}</td>
                                    <td class="py-2 tabular-nums">{{ number_format((float) $item->credit, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        @empty
            <div class="rounded-lg border border-line bg-white">
                <x-empty-state title="No journal entries linked to this loan" text="Double-entry postings for this loan appear here." />
            </div>
        @endforelse
    @else
        <x-data-table :headings="['No.', 'Due', 'Principal', 'Interest', 'Fees', 'Instalment', 'Paid', 'Status']"
                      :is-empty="$rows->isEmpty()"
                      empty-title="No schedule yet"
                      empty-text="A schedule is created when the money is sent. Older loans may have none.">
            @foreach ($rows as $item)
                @php
                    $paidSoFar = bcadd(bcadd((string) $item->fees_paid, (string) $item->interest_paid, 2), (string) $item->principal_paid, 2);
                @endphp
                <tr>
                    <td class="px-4 py-3 font-medium">{{ $item->installment_number }}</td>
                    <td class="px-4 py-3">{{ $date($item->due_date) }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ $money($item->principal_due) }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ $money($item->interest_due) }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ $money($item->fees_due) }}</td>
                    <td class="px-4 py-3 tabular-nums font-medium">{{ $money($item->total_installment_due) }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ $money($paidSoFar) }}</td>
                    <td class="px-4 py-3"><x-status-badge :status="$item->status" /></td>
                </tr>
            @endforeach
        </x-data-table>
    @endif
</x-layouts.lender>