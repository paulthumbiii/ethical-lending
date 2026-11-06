@php
    $money = fn (string $v): string => 'KES ' . number_format((float) $v, 2);
    $pretty = fn (string $v): string => ucwords(strtolower(str_replace('_', ' ', $v)));
    $tracking = $loan?->inDuplumTracking;
    $promiseTone = fn (string $s): string => match ($s) {
        'honored' => 'success',
        'broken'  => 'danger',
        'pending' => 'warning',
        default   => 'neutral',
    };

    // Consequence ladder: strictly compile events that exist in real data.
    $events = collect();

    if ($case->created_at) {
        $events->push([
            'title'  => 'Collection case opened (' . ($buckets[$case->dpd_bucket] ?? $case->dpd_bucket) . ')',
            'date'   => $case->created_at,
            'tone'   => 'brand',
            'detail' => 'Automatic collection monitoring initiated under fair collections rules.',
        ]);
    }

    if ($loan?->defaulted_at) {
        $events->push([
            'title'  => 'Loan defaulted (90+ days past due)',
            'date'   => $loan->defaulted_at,
            'tone'   => 'danger',
            'detail' => 'Default principal snapshot recorded. Statutory Section 44A in-duplum cap activated.',
        ]);
    }

    if ($loan) {
        foreach ($loan->restructuringProposals as $proposal) {
            if ($proposal->status === 'accepted' && $proposal->accepted_at) {
                $events->push([
                    'title'  => 'Relief plan accepted',
                    'date'   => $proposal->accepted_at,
                    'tone'   => 'success',
                    'detail' => 'Tenure extended by ' . $proposal->tenure_extension_days . ' days. Penalties forgiven and schedule restructured.',
                ]);
            }
        }
    }

    foreach ($case->promisesToPay as $ptp) {
        $ptpAmount = $money((string) $ptp->promised_amount);

        $events->push([
            'title'  => 'Promise to pay recorded (' . $ptpAmount . ')',
            'date'   => $ptp->created_at,
            'tone'   => 'warning',
            'detail' => 'Promised by ' . $ptp->promised_date->format('d M Y') . '. Automated reminders paused.',
        ]);

        if ($ptp->status === 'honored') {
            $events->push([
                'title'  => 'Promise honored (' . $ptpAmount . ')',
                'date'   => $ptp->honored_at ?? $ptp->updated_at,
                'tone'   => 'success',
                'detail' => 'Payment received in full on or before the promised date.',
            ]);
        } elseif ($ptp->status === 'broken') {
            $events->push([
                'title'  => 'Promise broken (' . $ptpAmount . ')',
                'date'   => $ptp->updated_at,
                'tone'   => 'danger',
                'detail' => 'Payment was not received by ' . $ptp->promised_date->format('d M Y') . '. Reminders resumed.',
            ]);
        }
    }

    if ($tracking?->is_frozen) {
        $events->push([
            'title'  => 'Charges frozen at statutory cap',
            'date'   => $tracking->frozen_at ?? $case->updated_at,
            'tone'   => 'danger',
            'detail' => 'Post-default interest and fees reached the principal limit. Statutory freeze applied.',
        ]);
    }

    $ladder = $events->sortBy(fn ($e) => $e['date']?->timestamp ?? 0)->values();
@endphp
<x-layouts.lender :title="'Case ' . ($loan?->loan_number ?? 'unknown')">
    <div class="space-y-6">
        <x-toast />

        <p class="text-sm">
            <a href="{{ route('lender.collections.index') }}" class="text-brand-700 underline-offset-4 hover:underline">&larr; Back to the workbench</a>
        </p>

        <x-card title="Case summary">
            <dl class="grid grid-cols-2 gap-4 text-sm lg:grid-cols-4">
                <div>
                    <dt class="text-xs text-muted">Loan</dt>
                    <dd class="flex items-center gap-1.5 font-medium tabular-nums" x-data="{ copied: false }">
                        <span>{{ $loan?->loan_number ?? 'Unknown' }}</span>
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
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">Borrower</dt>
                    <dd class="font-medium">{{ $loan?->borrower?->full_name ?? 'Unknown' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">Case status</dt>
                    <dd class="font-medium">{{ $pretty((string) $case->case_status) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">Last contacted (EAT)</dt>
                    <dd class="font-medium tabular-nums">{{ $case->last_contacted_at?->timezone('Africa/Nairobi')->format('d M Y, H:i') ?? 'Never' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">Days past due</dt>
                    <dd class="font-medium tabular-nums">
                        @if ($days > 0)
                            {{ $days }} {{ $days === 1 ? 'day' : 'days' }}
                        @else
                            Not overdue
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">Bucket</dt>
                    <dd class="font-medium">{{ $buckets[$case->dpd_bucket] ?? $case->dpd_bucket }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">Overdue since</dt>
                    <dd class="font-medium tabular-nums">{{ $since ? $since->format('d M Y') : 'Not overdue' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">Charged since default</dt>
                    <dd class="font-medium tabular-nums">
                        @if ($tracking)
                            {{ $money(bcadd((string) $tracking->total_post_default_interest, (string) $tracking->total_post_default_penalties, 2)) }}
                            of {{ $money((string) $tracking->statutory_cap_limit) }}
                        @else
                            Not in default yet
                        @endif
                    </dd>
                </div>
            </dl>
        </x-card>

        {{-- Consequence Ladder: Real-data progression timeline --}}
        <x-card title="Case ladder & recovery timeline">
            <ol class="relative ml-2 border-l border-line">
                @foreach ($ladder as $step)
                    @php
                        $dot = match ($step['tone']) {
                            'danger'  => 'bg-rose-500',
                            'success' => 'bg-emerald-600',
                            'warning' => 'bg-amber-500',
                            default   => 'bg-brand-600',
                        };
                    @endphp
                    <li class="relative pb-5 pl-6 text-sm last:pb-0">
                        <span class="absolute -left-[5px] top-1.5 h-2.5 w-2.5 rounded-full {{ $dot }}" aria-hidden="true"></span>
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <p class="font-medium text-ink">{{ $step['title'] }}</p>
                            <span class="text-xs tabular-nums text-muted">{{ $step['date']?->timezone('Africa/Nairobi')->format('d M Y, H:i') ?? '-' }}</span>
                        </div>
                        <p class="mt-0.5 text-xs text-muted">{{ $step['detail'] }}</p>
                    </li>
                @endforeach
            </ol>
            <p class="mt-4 border-t border-line pt-2 text-2xs text-muted">Only events recorded in system data are shown. Future actions (CRB notice, field assignment) only appear when initiated.</p>
        </x-card>

        @if ($canRecord)
            @if ($live)
                <x-card title="Promise to pay">
                    <p class="text-sm">A promise to pay is already live on this case. Automatic reminders are paused until {{ $live->promised_date->format('d M Y') }}.</p>
                </x-card>
            @else
                <x-card title="Record promise">
                    <form method="POST"
                          action="{{ route('lender.collections.promises.store', $case) }}"
                          x-on:submit="if (! confirm('Record this promise to pay? Automatic escalation will pause until the promised date.')) $event.preventDefault()"
                          class="grid gap-4 sm:grid-cols-3 sm:items-end">
                        @csrf
                        <x-input label="Amount promised (KES)" name="amount" type="text" inputmode="decimal" :value="old('amount')" />
                        <x-input label="Promised date" name="promised_date" type="date" :value="old('promised_date')" min="{{ $minDate }}" max="{{ $maxDate }}" />
                        <div>
                            <x-button type="submit">Save promise</x-button>
                        </div>
                    </form>
                    <p class="mt-3 text-xs text-muted">
                        While a promise is pending, automatic reminders pause. If the money does not arrive by the promised date, the promise is marked broken overnight and reminders resume.
                    </p>
                </x-card>
            @endif
        @endif

        <x-card title="Promise history">
            @if ($case->promisesToPay->isEmpty())
                <p class="text-sm text-muted">No promises have been made on this case.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[36rem] text-left text-sm">
                        <thead class="text-xs text-muted">
                            <tr>
                                <th class="py-2 pr-4 font-medium">Promised for</th>
                                <th class="py-2 pr-4 font-medium">Amount</th>
                                <th class="py-2 pr-4 font-medium">Recorded by</th>
                                <th class="py-2 pr-4 font-medium">Outcome</th>
                                <th class="py-2 font-medium">Honored on (EAT)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($case->promisesToPay as $promise)
                                <tr>
                                    <td class="py-3 pr-4 tabular-nums">{{ $promise->promised_date->format('d M Y') }}</td>
                                    <td class="py-3 pr-4 tabular-nums">{{ $money((string) $promise->promised_amount) }}</td>
                                    <td class="py-3 pr-4">{{ $recordedBy[$promise->id] ?? 'Not recorded' }}</td>
                                    <td class="py-3 pr-4"><x-badge tone="{{ $promiseTone((string) $promise->status) }}">{{ $pretty((string) $promise->status) }}</x-badge></td>
                                    <td class="py-3 tabular-nums">{{ $promise->honored_at?->timezone('Africa/Nairobi')->format('d M Y, H:i') ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>

        <x-card title="Outreach log">
            @if ($case->actions->isEmpty())
                <p class="text-sm text-muted">No reminders have been sent on this case.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-left text-sm">
                        <thead class="text-xs text-muted">
                            <tr>
                                <th class="py-2 pr-4 font-medium">When (EAT)</th>
                                <th class="py-2 pr-4 font-medium">Type</th>
                                <th class="py-2 pr-4 font-medium">Status</th>
                                <th class="py-2 font-medium">Message</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($case->actions as $action)
                                <tr class="align-top">
                                    <td class="py-3 pr-4 tabular-nums">{{ $action->created_at?->timezone('Africa/Nairobi')->format('d M Y, H:i') ?? '-' }}</td>
                                    <td class="py-3 pr-4">{{ $action->action_type }}</td>
                                    <td class="py-3 pr-4">{{ $pretty((string) ($action->gateway_status ?? 'unknown')) }}</td>
                                    <td class="py-3 text-xs text-muted">{{ $action->message_payload }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-layouts.lender>