<div>
    <!-- He who is contented is rich. - Laozi -->
</div>
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
        <x-data-table :headings="['No.', 'Due', 'Principal', 'Interest', 'Fees', 'Instalment', 'Status']"
                      :is-empty="$rows->isEmpty()"
                      empty-title="No schedule yet"
                      empty-text="Instalment schedules are created at payout in a later phase.">
            @foreach ($rows as $item)
                <tr>
                    <td class="px-4 py-3 font-medium">{{ $item->installment_number }}</td>
                    <td class="px-4 py-3">{{ $date($item->due_date) }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ $money($item->principal_due) }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ $money($item->interest_due) }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ $money($item->fees_due) }}</td>
                    <td class="px-4 py-3 tabular-nums font-medium">{{ $money($item->total_installment_due) }}</td>
                    <td class="px-4 py-3"><x-status-badge :status="$item->status" /></td>
                </tr>
            @endforeach
        </x-data-table>
    @endif
</x-layouts.lender>