<x-layouts.lender title="Audit log">
    @php
        $inputClass = 'rounded border border-line bg-white px-2 py-1 text-sm';
    @endphp

    <div class="space-y-4">
        <x-page-header subtitle="Who did what, and when. Old and new values are not shown here because they can contain personal data." />

        <x-filter-bar :action="route('lender.audit.index')">
            <label class="text-sm">Action
                <select name="action" class="{{ $inputClass }}">
                    <option value="">All actions</option>
                    @foreach ($actions as $a)
                        <option value="{{ $a }}" @selected($filters['action'] === $a)>{{ ucwords(strtolower(str_replace('_', ' ', $a))) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">Entity
                <select name="entity" class="{{ $inputClass }}">
                    <option value="">All entities</option>
                    @foreach ($entities as $e)
                        <option value="{{ $e }}" @selected($filters['entity'] === $e)>{{ class_basename($e) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">User
                <input type="text" name="user" value="{{ $filters['user'] }}" placeholder="Name or email" class="{{ $inputClass }}">
            </label>
            <label class="text-sm">From
                <input type="date" name="from" value="{{ $filters['from'] }}" class="{{ $inputClass }}">
            </label>
            <label class="text-sm">To
                <input type="date" name="to" value="{{ $filters['to'] }}" class="{{ $inputClass }}">
            </label>
        </x-filter-bar>

        <x-data-table
            :headings="['When', 'Who', 'Action', 'Entity', 'IP address']"
            :is-empty="$rows->isEmpty()"
            empty-title="No audit entries"
            empty-text="Nothing matches these filters."
        >
            @foreach ($rows as $r)
                <tr>
                    <td class="px-4 py-3 whitespace-nowrap">{{ $r->created_at?->format('d M Y H:i:s') }}</td>
                    <td class="px-4 py-3">
                        @if ($r->user)
                            {{ $r->user->name }}
                            <div class="text-xs text-muted">{{ $r->user->email }}</div>
                        @else
                            <span class="text-muted">Unknown / not signed in</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 font-medium">{{ ucwords(strtolower(str_replace('_', ' ', $r->action))) }}</td>
                    <td class="px-4 py-3">{{ class_basename($r->auditable_type) }} #{{ $r->auditable_id }}</td>
                    <td class="px-4 py-3">{{ $r->ip_address }}</td>
                </tr>
            @endforeach
        </x-data-table>

        <x-pager :paginator="$rows" />
    </div>
</x-layouts.lender>