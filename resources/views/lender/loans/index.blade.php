@php
    $money = fn ($v): string => 'KES ' . number_format((float) $v, 2);
@endphp
<x-layouts.lender title="Loans">
    <x-page-header subtitle="Every loan, newest first. Balances come from the latest ledger row." />

    <x-filter-bar :action="route('lender.loans.index')">
        <div class="flex-1 min-w-[14rem]">
            <label for="q" class="block text-xs font-medium text-muted">Search</label>
            <input id="q" name="q" type="search" value="{{ $q }}" placeholder="Loan number, borrower or phone"
                   class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm focus:border-brand-600 focus:outline-none">
        </div>
        <div>
            <label for="status" class="block text-xs font-medium text-muted">Status</label>
            <select id="status" name="status" class="mt-1 rounded-md border border-line bg-white px-3 py-2 text-sm focus:border-brand-600 focus:outline-none">
                <option value="">All</option>
                @foreach (\App\Enums\LoanStatus::cases() as $case)
                    <option value="{{ $case->value }}" @selected($status === $case->value)>{{ ucwords(strtolower(str_replace('_', ' ', $case->value))) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-center gap-2 pb-2">
            <input id="frozen" name="frozen" type="checkbox" value="1" @checked($frozen) class="h-4 w-4 rounded border-line accent-brand-700">
            <label for="frozen" class="text-sm">Frozen by in-duplum</label>
        </div>
    </x-filter-bar>

    {{-- Mobile view: Stacked loan cards --}}
    @if ($loans->isNotEmpty())
        <div class="mb-4 divide-y divide-line rounded-lg border border-line bg-white p-4 md:hidden">
            @foreach ($loans as $loan)
                <div class="py-3 space-y-1.5 first:pt-0 last:pb-0 text-sm">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex items-center gap-1.5" x-data="{ copied: false }">
                            <a href="{{ route('lender.loans.show', $loan) }}" class="font-medium text-brand-700 underline-offset-4 hover:underline">{{ $loan->loan_number }}</a>
                            <button type="button"
                                    x-on:click.stop="navigator.clipboard.writeText('{{ $loan->loan_number }}'); copied = true; setTimeout(() => copied = false, 1500)"
                                    class="text-muted hover:text-ink" title="Copy loan number" aria-label="Copy loan number">
                                <svg x-show="!copied" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                <svg x-show="copied" x-cloak class="h-3.5 w-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            </button>
                        </div>
                        <div class="flex items-center gap-1">
                            <x-status-badge :status="$loan->status->value" />
                            @if ($loan->is_in_duplum_locked)
                                <x-badge tone="danger">Frozen</x-badge>
                            @endif
                        </div>
                    </div>
                    <p class="text-xs text-muted">{{ $loan->borrower?->full_name ?? '-' }} &bull; {{ $loan->product?->name ?? '-' }}</p>
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div>Principal: <span class="tabular-nums font-medium text-ink">{{ $money($loan->principal_disbursed) }}</span></div>
                        <div>Balance: <span class="tabular-nums font-medium text-ink">{{ isset($balances[$loan->id]) ? $money($balances[$loan->id]) : '-' }}</span></div>
                    </div>
                    <div class="flex items-center justify-between pt-1 text-xs text-muted">
                        <span>Due: <span class="tabular-nums">{{ $loan->due_date?->format('j M Y') ?? '-' }}</span></span>
                        <a href="{{ route('lender.loans.show', $loan) }}" class="text-brand-700 font-medium">View loan &rarr;</a>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Desktop view: Data table --}}
    <div class="hidden md:block">
        <x-data-table :headings="['Loan', 'Borrower', 'Product', 'Principal', 'Balance', 'Status', 'Due']"
                      :is-empty="$loans->isEmpty()"
                      empty-title="No loans found"
                      empty-text="Try a different search or clear the filters.">
            @foreach ($loans as $loan)
                <tr>
                    <td class="px-4 py-3 font-medium">
                        <div class="flex items-center gap-1.5" x-data="{ copied: false }">
                            <a href="{{ route('lender.loans.show', $loan) }}" class="text-brand-700 underline-offset-4 hover:underline">{{ $loan->loan_number }}</a>
                            <button type="button"
                                    x-on:click.stop="navigator.clipboard.writeText('{{ $loan->loan_number }}'); copied = true; setTimeout(() => copied = false, 1500)"
                                    class="text-muted transition-colors hover:text-ink"
                                    title="Copy loan number"
                                    aria-label="Copy loan number">
                                <svg x-show="!copied" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                <svg x-show="copied" x-cloak class="h-3.5 w-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            </button>
                        </div>
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
    </div>

    @if ($loans->isEmpty())
        <div class="md:hidden">
            <x-card>
                <x-empty-state title="No loans found" text="Try a different search or clear the filters." />
            </x-card>
        </div>
    @endif

    <x-pager :paginator="$loans" />
</x-layouts.lender>