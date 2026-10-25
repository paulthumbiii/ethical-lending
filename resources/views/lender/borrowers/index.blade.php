<x-layouts.lender title="Borrowers">
    <x-page-header subtitle="Everyone who has registered or been added, newest details first by name." />

    <x-filter-bar :action="route('lender.borrowers.index')">
        <div>
            <label for="q" class="block text-xs font-medium text-muted">Search</label>
            <input id="q" name="q" type="search" value="{{ $q }}" placeholder="Name, phone, ID or email"
                   class="mt-1 w-64 rounded-md border border-line bg-white px-3 py-2 text-sm">
        </div>
        <div>
            <label for="status" class="block text-xs font-medium text-muted">Status</label>
            <select id="status" name="status" class="mt-1 rounded-md border border-line bg-white px-3 py-2 text-sm">
                <option value="all" @selected($status === 'all')>All</option>
                <option value="clear" @selected($status === 'clear')>Not blacklisted</option>
                <option value="blacklisted" @selected($status === 'blacklisted')>Blacklisted</option>
            </select>
        </div>
    </x-filter-bar>

    <x-data-table :headings="['Name', 'Phone', 'ID number', 'Band', 'Credit limit', 'Loans', 'Status']"
                  :is-empty="$borrowers->isEmpty()"
                  empty-title="No borrowers found"
                  empty-text="Try a different search or clear the filters.">
        @foreach ($borrowers as $b)
            <tr>
                <td class="px-4 py-3 font-medium">
                    <a href="{{ route('lender.borrowers.show', $b) }}" class="text-brand-700 underline-offset-4 hover:underline">{{ $b->full_name }}</a>
                </td>
                <td class="px-4 py-3 tabular-nums">{{ $b->phone_number }}</td>
                <td class="px-4 py-3 tabular-nums">{{ $b->id_number }}</td>
                <td class="px-4 py-3">{{ $b->risk_band?->value ?? '-' }}</td>
                <td class="px-4 py-3 tabular-nums">KES {{ number_format((float) $b->credit_limit, 2) }}</td>
                <td class="px-4 py-3 tabular-nums">{{ $b->loans_count }}</td>
                <td class="px-4 py-3">
                    @if ($b->is_blacklisted)
                        <x-badge tone="danger">Blacklisted</x-badge>
                    @else
                        <x-badge tone="success">Clear</x-badge>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-data-table>

    <x-pager :paginator="$borrowers" />
</x-layouts.lender>