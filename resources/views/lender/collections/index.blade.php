@php
    $money = fn (string $v): string => 'KES ' . number_format((float) $v, 2);
    $pretty = fn (string $v): string => ucwords(strtolower(str_replace('_', ' ', $v)));
@endphp
<x-layouts.lender title="Collections workbench">
    <div class="space-y-6">

        @if ($errors->any())
            <div role="alert" class="rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ $errors->first() }}
            </div>
        @endif

        <x-card>
            <div class="flex flex-wrap items-center gap-3">
                <x-badge tone="{{ $withinHours ? 'success' : 'warning' }}">{{ $withinHours ? 'Contact window open' : 'Outside legal hours' }}</x-badge>
                <p class="text-sm">
                    It is {{ $nowEat }} in Nairobi. Reminders may only go out between {{ $hourStart }} and {{ $hourEnd }} EAT,
                    with {{ $cooldownHours }} hours between contacts.
                </p>
            </div>
            <p class="mt-3 text-xs text-muted">
                This page is read-only. Reminders are sent by the system under the fair collections rules
                (CBK Digital Credit Providers Regulations 2022, Reg. 20). Nothing here can send a message or call a borrower.
            </p>
        </x-card>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
            <div class="rounded-lg border border-line bg-white">
                <x-stat label="Cases" :value="$stats['cases']" hint="All collection cases" />
            </div>
            <div class="rounded-lg border border-line bg-white">
                <x-stat label="Quiet period" :value="$stats['cooldown']" hint="Borrowers we cannot contact yet" />
            </div>
            <div class="rounded-lg border border-line bg-white">
                <x-stat label="Promises to pay" :value="$stats['ptp']" hint="Escalation is paused" />
            </div>
            <div class="rounded-lg border border-line bg-white">
                <x-stat label="Frozen at cap" :value="$stats['frozen']" hint="In-duplum, charges stopped" />
            </div>
            <div class="rounded-lg border border-line bg-white">
                <x-stat label="Defaulted today" :value="$stats['defaulted_today']" hint="Reached 90 days past due" />
            </div>
        </div>

        <x-card title="Filters">
            <form method="GET" action="{{ route('lender.collections.index') }}" class="flex flex-wrap items-end gap-4">
                <div>
                    <label for="f-bucket" class="block text-xs font-medium text-muted">Days past due</label>
                    <select id="f-bucket" name="bucket" class="mt-1 rounded-md border border-line bg-white px-3 py-2 text-sm">
                        <option value="">Any</option>
                        @foreach ($buckets as $key => $label)
                            <option value="{{ $key }}" @selected($filters['bucket'] === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="f-cooldown" class="block text-xs font-medium text-muted">Quiet period</label>
                    <select id="f-cooldown" name="cooldown" class="mt-1 rounded-md border border-line bg-white px-3 py-2 text-sm">
                        <option value="">Any</option>
                        <option value="active" @selected($filters['cooldown'] === 'active')>In a quiet period</option>
                        <option value="none" @selected($filters['cooldown'] === 'none')>Not in a quiet period</option>
                    </select>
                </div>

                <div>
                    <label for="f-ptp" class="block text-xs font-medium text-muted">Promise to pay</label>
                    <select id="f-ptp" name="ptp" class="mt-1 rounded-md border border-line bg-white px-3 py-2 text-sm">
                        <option value="">Any</option>
                        <option value="active" @selected($filters['ptp'] === 'active')>Has a promise to pay</option>
                        <option value="none" @selected($filters['ptp'] === 'none')>No promise to pay</option>
                    </select>
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="rounded-md bg-brand-900 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800">Apply</button>
                    <a href="{{ route('lender.collections.index') }}" class="rounded-md border border-line px-4 py-2 text-sm font-medium hover:bg-paper">Clear</a>
                </div>
            </form>
        </x-card>

        <x-card title="Cases ({{ $matching }})">
            @if ($cases->isEmpty())
                <p class="text-sm text-muted">No cases match these filters.</p>
            @else
                {{-- Mobile view: Stacked cards --}}
                <div class="divide-y divide-line md:hidden">
                    @foreach ($cases as $case)
                        @php
                            $loan = $case->loan;
                            $tracking = $loan?->inDuplumTracking;
                            $lock = $locks[$case->borrower_id] ?? null;
                            $ptp = $case->promisesToPay->first();
                            $od = $overdue[$case->id] ?? ['days' => 0, 'since' => null];

                            $used = null;
                            $cap = null;
                            $barWidth = 0.0;
                            $barColour = 'bg-brand-600';
                            if ($tracking) {
                                $used = bcadd((string) $tracking->total_post_default_interest, (string) $tracking->total_post_default_penalties, 2);
                                $cap = (string) $tracking->statutory_cap_limit;
                                $pct = bccomp($cap, '0.00', 2) > 0
                                    ? bcmul(bcdiv($used, $cap, 4), '100.00', 2)
                                    : '0.00';
                                $barWidth = min(100.0, (float) $pct);
                                if ($tracking->is_frozen) {
                                    $barColour = 'bg-rose-600';
                                } elseif ($barWidth >= 80.0) {
                                    $barColour = 'bg-amber-500';
                                }
                            }
                        @endphp
                        <div class="py-4 space-y-2 text-sm">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex items-center gap-1.5" x-data="{ copied: false }">
                                    <a href="{{ route('lender.collections.show', $case) }}" class="font-medium tabular-nums text-brand-700 underline">{{ $loan?->loan_number ?? 'Unknown' }}</a>
                                    @if ($loan?->loan_number)
                                        <button type="button"
                                                x-on:click.stop="navigator.clipboard.writeText('{{ $loan->loan_number }}'); copied = true; setTimeout(() => copied = false, 1500)"
                                                class="text-muted hover:text-ink" title="Copy loan number" aria-label="Copy loan number">
                                            <svg x-show="!copied" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                            <svg x-show="copied" x-cloak class="h-3.5 w-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                        </button>
                                    @endif
                                    <span class="text-xs text-muted">({{ $pretty((string) $case->case_status) }})</span>
                                </div>
                                <span class="text-xs text-muted">{{ $buckets[$case->dpd_bucket] ?? $case->dpd_bucket }}</span>
                            </div>

                            <p class="font-medium">{{ $loan?->borrower?->full_name ?? 'Unknown' }}</p>

                            <div class="grid grid-cols-2 gap-2 text-xs">
                                <div>
                                    <span class="text-muted">Overdue: </span>
                                    @if ($od['days'] > 0)
                                        <span class="font-medium tabular-nums">{{ $od['days'] }} {{ $od['days'] === 1 ? 'day' : 'days' }}</span>
                                        @if ($od['since'])
                                            <span class="block text-muted tabular-nums">since {{ $od['since']->format('d M Y') }}</span>
                                        @endif
                                    @else
                                        <span class="text-muted">Not overdue</span>
                                    @endif
                                </div>
                                <div>
                                    <span class="text-muted">Quiet period: </span>
                                    @if ($lock)
                                        <x-badge tone="info">Quiet period</x-badge>
                                        <span class="block text-muted tabular-nums">about {{ max(1, (int) ceil(abs($now->diffInMinutes($lock->locked_until)) / 60)) }} h left</span>
                                    @else
                                        <span class="text-muted">None</span>
                                    @endif
                                </div>
                            </div>

                            @if ($ptp)
                                <div class="rounded bg-paper p-2 text-xs">
                                    <div class="flex items-center justify-between">
                                        <x-badge tone="success">Promise made</x-badge>
                                        <span class="tabular-nums font-medium text-ink">{{ $money((string) $ptp->promised_amount) }}</span>
                                    </div>
                                    <p class="mt-1 text-muted">Due {{ $ptp->promised_date->format('d M Y') }}</p>
                                </div>
                            @endif

                            @if ($tracking)
                                <div class="text-xs">
                                    <div class="flex justify-between">
                                        <span class="text-muted">Charged:</span>
                                        <span class="tabular-nums">{{ $money($used) }} of {{ $money($cap) }}</span>
                                    </div>
                                    <div class="mt-1 h-1.5 w-full rounded-full bg-line" role="img" aria-label="Share of the legal cap used">
                                        <div class="h-1.5 rounded-full {{ $barColour }}" style="width: {{ number_format($barWidth, 2, '.', '') }}%"></div>
                                    </div>
                                    @if ($tracking->is_frozen)
                                        <x-badge tone="danger" class="mt-1">Frozen at cap</x-badge>
                                    @endif
                                </div>
                            @endif

                            <div class="flex items-center justify-between pt-1 text-xs text-muted">
                                <span>Last contacted: {{ $case->last_contacted_at?->timezone('Africa/Nairobi')->format('d M Y, H:i') ?? 'Never' }}</span>
                                <a href="{{ route('lender.collections.show', $case) }}" class="font-medium text-brand-700 underline">Open case &rarr;</a>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Desktop view: Full data table --}}
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full min-w-[56rem] text-left text-sm">
                        <thead class="text-xs text-muted">
                            <tr>
                                <th class="py-2 pr-4 font-medium">Loan</th>
                                <th class="py-2 pr-4 font-medium">Borrower</th>
                                <th class="py-2 pr-4 font-medium">Days past due</th>
                                <th class="py-2 pr-4 font-medium">Quiet period</th>
                                <th class="py-2 pr-4 font-medium">Promise to pay</th>
                                <th class="py-2 pr-4 font-medium">Charged since default</th>
                                <th class="py-2 font-medium">Last contacted (EAT)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($cases as $case)
                                @php
                                    $loan = $case->loan;
                                    $tracking = $loan?->inDuplumTracking;
                                    $lock = $locks[$case->borrower_id] ?? null;
                                    $ptp = $case->promisesToPay->first();
                                    $od = $overdue[$case->id] ?? ['days' => 0, 'since' => null];

                                    $used = null;
                                    $cap = null;
                                    $barWidth = 0.0;
                                    $barColour = 'bg-brand-600';
                                    if ($tracking) {
                                        $used = bcadd((string) $tracking->total_post_default_interest, (string) $tracking->total_post_default_penalties, 2);
                                        $cap = (string) $tracking->statutory_cap_limit;
                                        $pct = bccomp($cap, '0.00', 2) > 0
                                            ? bcmul(bcdiv($used, $cap, 4), '100.00', 2)
                                            : '0.00';
                                        $barWidth = min(100.0, (float) $pct);
                                        if ($tracking->is_frozen) {
                                            $barColour = 'bg-rose-600';
                                        } elseif ($barWidth >= 80.0) {
                                            $barColour = 'bg-amber-500';
                                        }
                                    }
                                @endphp
                                <tr class="align-top">
                                    <td class="py-3 pr-4">
                                        <div class="flex items-center gap-1.5" x-data="{ copied: false }">
                                            <a href="{{ route('lender.collections.show', $case) }}" class="font-medium tabular-nums text-brand-700 underline">{{ $loan?->loan_number ?? 'Unknown' }}</a>
                                            @if ($loan?->loan_number)
                                                <button type="button"
                                                        x-on:click.stop="navigator.clipboard.writeText('{{ $loan->loan_number }}'); copied = true; setTimeout(() => copied = false, 1500)"
                                                        class="text-muted transition-colors hover:text-ink"
                                                        title="Copy loan number"
                                                        aria-label="Copy loan number">
                                                    <svg x-show="!copied" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                                    <svg x-show="copied" x-cloak class="h-3.5 w-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                </button>
                                            @endif
                                        </div>
                                        <p class="text-xs text-muted">{{ $pretty((string) $case->case_status) }}</p>
                                    </td>
                                    <td class="py-3 pr-4">{{ $loan?->borrower?->full_name ?? 'Unknown' }}</td>
                                    <td class="py-3 pr-4">
                                        @if ($od['days'] > 0)
                                            <p class="font-medium tabular-nums">{{ $od['days'] }} {{ $od['days'] === 1 ? 'day' : 'days' }}</p>
                                            <p class="text-xs text-muted">{{ $buckets[$case->dpd_bucket] ?? $case->dpd_bucket }}</p>
                                            @if ($od['since'])
                                                <p class="text-xs tabular-nums text-muted">Overdue since {{ $od['since']->format('d M Y') }}</p>
                                            @endif
                                        @else
                                            <p class="text-muted">Not overdue</p>
                                            <p class="text-xs text-muted">{{ $buckets[$case->dpd_bucket] ?? $case->dpd_bucket }}</p>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-4">
                                        @if ($lock)
                                            <x-badge tone="info">Quiet period</x-badge>
                                            <p class="mt-1 text-xs tabular-nums text-muted">
                                                until {{ $lock->locked_until->timezone('Africa/Nairobi')->format('d M, H:i') }},
                                                about {{ max(1, (int) ceil(abs($now->diffInMinutes($lock->locked_until)) / 60)) }} h left
                                            </p>
                                        @else
                                            <span class="text-muted">None</span>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-4">
                                        @if ($ptp)
                                            <x-badge tone="success">Promise made</x-badge>
                                            <p class="mt-1 text-xs tabular-nums text-muted">
                                                {{ $money((string) $ptp->promised_amount) }} by {{ $ptp->promised_date->format('d M Y') }}
                                            </p>
                                        @else
                                            <span class="text-muted">None</span>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-4">
                                        @if ($tracking)
                                            <p class="text-xs tabular-nums">{{ $money($used) }} of {{ $money($cap) }}</p>
                                            <div class="mt-1 h-1.5 w-32 rounded-full bg-line" role="img" aria-label="Share of the legal cap used">
                                                <div class="h-1.5 rounded-full {{ $barColour }}" style="width: {{ number_format($barWidth, 2, '.', '') }}%"></div>
                                            </div>
                                            @if ($tracking->is_frozen)
                                                <x-badge tone="danger" class="mt-1">Frozen at cap</x-badge>
                                            @endif
                                        @else
                                            <span class="text-muted">Not in default yet</span>
                                        @endif
                                    </td>
                                    <td class="py-3 tabular-nums">
                                        {{ $case->last_contacted_at?->timezone('Africa/Nairobi')->format('d M Y, H:i') ?? 'Never' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($matching > $cases->count())
                    <p class="mt-3 text-xs text-muted">Showing the {{ $cases->count() }} most overdue of {{ $matching }}. Use the filters to narrow down.</p>
                @endif
            @endif
        </x-card>
    </div>
</x-layouts.lender>