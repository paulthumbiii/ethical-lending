@php
    $money = fn ($v): string => 'KES ' . number_format((float) $v, 2);
@endphp
<x-layouts.lender title="Loans">
    <x-page-header subtitle="Every loan, newest first. Balances come from the latest ledger row." />

    <x-filter-bar :action="route('lender.loans.index')">
        <div>
            <label for="q" class="block text-xs font-medium text-muted">Search</label>
            <input id="q" name="q" type="search" value="{{ $q }}" placeholder="Loan number, borrower or phone"
                   class="mt-1 w-64 rounded-md border border-line bg-white px-3 py-2 text-sm">
        </div>
        <div>
            <label for="status" class="block text-xs font-medium text-muted">Status</label>
            <select id="status" name="status" class="mt-1 rounded-md border border-line bg-white px-3 py-2 text-sm">
                <option value="">All</option>
                @foreach (\App\Enums\LoanStatus::cases() as $case)
                    <option value="{{ $case->value }}" @selected($status === $case->value)>{{ ucwords(strtolower(str_replace('_', ' ', $case->value))) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-center gap-2 pb-2">
            <input id="frozen" name="frozen" type="checkbox" value="1" @checked($frozen) class="h-4 w-4 rounded border-line">
            <label for="frozen" class="text-sm">Frozen by in-duplum</label>
        </div>
    </x-filter-bar>

    <x-data-table :headings="['Loan', 'Borrower', 'Product', 'Principal', 'Balance', 'Status', 'Due']"
                  :is-empty="$loans->isEmpty()"
                  empty-title="No loans found"
                  empty-text="Try a different search or clear the filters.">
        @foreach ($loans as $loan)
            <tr>
                <td class="px-4 py-3 font-medium">
                    <a href="{{ route('lender.loans.show', $loan) }}" class="text-brand-700 underline-offset-4 hover:underline">{{ $loan->loan_number }}</a>
                </td>
                <td class="px-4 py-3">{{ $loan->borrower?->full_name ?? '-' }}</td>
                <td class="px-4 py-3">{{ $loan->product?->name ?? '-' }}</td>
                <td class="px-4 py-3 tabular-nums">{{ $money($loan->principal_disbursed) }}</td>
                <td class="px-4 py-3 tabular-nums">{{ isset($balances[$loan->id]) ? $money($balances[$loan->id]) : '-' }}</td>
                <td class="px-4 py-3">
                    <x-status-badge :status="$loan->status->value" />
                    @if ($loan->is_in_duplum_locked)
                        <x-badge tone="danger">Frozen</x-badge>
                    @endif
                </td>
                <td class="px-4 py-3">{{ $loan->due_date?->format('j M Y') ?? '-' }}</td>
            </tr>
        @endforeach
    </x-data-table>

    <x-pager :paginator="$loans" />
</x-layouts.lender>