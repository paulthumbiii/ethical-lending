@php
    $pretty = fn (string $v): string => ucwords(strtolower(str_replace('_', ' ', $v)));
    $label = fn (string $s): string => match ($s) {
        'submitted', 'pending' => 'Waiting for check',
        'approved_pending_checker' => 'Awaiting second approver',
        'rejected' => 'Did not pass checks',
        'approved' => 'Approved',
        default => $pretty($s),
    };
    $tone = fn (string $s): string => match ($s) {
        'approved' => 'success',
        'rejected' => 'danger',
        default => 'warning',
    };
@endphp
<x-layouts.lender title="Applications">
    <div class="space-y-6">
        <p class="text-sm text-muted">
            Check each application against the lending rules. Passing a check does not approve a loan:
            a second person must approve it in the Review desk.
        </p>

        <x-card>
            <h2 class="text-sm font-semibold">Waiting for a check ({{ $waitingCount }})</h2>

            @if ($waiting->isEmpty())
                <p class="mt-3 text-sm text-muted">No applications are waiting.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[44rem] text-left text-sm">
                        <thead class="text-xs text-muted">
                            <tr>
                                <th class="py-2 pr-4 font-medium">Application</th>
                                <th class="py-2 pr-4 font-medium">Borrower</th>
                                <th class="py-2 pr-4 font-medium">Product</th>
                                <th class="py-2 pr-4 font-medium">Amount</th>
                                <th class="py-2 pr-4 font-medium">Submitted (EAT)</th>
                                <th class="py-2 font-medium"><span class="sr-only">Open</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($waiting as $a)
                                <tr class="align-top">
                                    <td class="py-3 pr-4 tabular-nums">{{ $a->application_number }}</td>
                                    <td class="py-3 pr-4">{{ $a->borrower?->full_name ?? 'Unknown' }}</td>
                                    <td class="py-3 pr-4">{{ $a->product?->name ?? 'Unknown' }}</td>
                                    <td class="py-3 pr-4 tabular-nums">KES {{ number_format((float) $a->requested_principal, 2) }}</td>
                                    <td class="py-3 pr-4 tabular-nums">{{ $a->created_at?->timezone('Africa/Nairobi')->format('d M Y, H:i') }}</td>
                                    <td class="py-3">
                                        <a href="{{ route('lender.applications.show', ['loanApplication' => $a->id]) }}"
                                           class="text-sm font-medium text-brand-700 underline-offset-4 hover:underline">Open</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($waitingCount > $waiting->count())
                    <p class="mt-3 text-xs text-muted">Showing the oldest {{ $waiting->count() }} of {{ $waitingCount }}.</p>
                @endif
            @endif
        </x-card>

        <x-card>
            <h2 class="text-sm font-semibold">Recently checked</h2>

            @if ($decided->isEmpty())
                <p class="mt-3 text-sm text-muted">No applications have been checked yet.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-left text-sm">
                        <thead class="text-xs text-muted">
                            <tr>
                                <th class="py-2 pr-4 font-medium">Application</th>
                                <th class="py-2 pr-4 font-medium">Borrower</th>
                                <th class="py-2 pr-4 font-medium">Amount</th>
                                <th class="py-2 pr-4 font-medium">Outcome</th>
                                <th class="py-2 font-medium"><span class="sr-only">Open</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($decided as $a)
                                <tr class="align-top">
                                    <td class="py-3 pr-4 tabular-nums">{{ $a->application_number }}</td>
                                    <td class="py-3 pr-4">{{ $a->borrower?->full_name ?? 'Unknown' }}</td>
                                    <td class="py-3 pr-4 tabular-nums">KES {{ number_format((float) $a->requested_principal, 2) }}</td>
                                    <td class="py-3 pr-4">
                                        <x-badge tone="{{ $tone((string) $a->status) }}">{{ $label((string) $a->status) }}</x-badge>
                                    </td>
                                    <td class="py-3">
                                        <a href="{{ route('lender.applications.show', ['loanApplication' => $a->id]) }}"
                                           class="text-sm font-medium text-brand-700 underline-offset-4 hover:underline">Open</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-layouts.lender>