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
@endphp
<x-layouts.lender :title="'Case ' . ($loan?->loan_number ?? 'unknown')">
    <div class="space-y-6">
        <x-toast />

        <p class="text-sm">
            <a href="{{ route('lender.collections.index') }}" class="text-brand-700 underline-offset-4 hover:underline">Back to the workbench</a>
        </p>

        <x-card title="Case summary">
            <dl class="grid grid-cols-2 gap-4 text-sm lg:grid-cols-4">
                <div>
                    <dt class="text-xs text-muted">Loan</dt>
                    <dd class="font-medium tabular-nums">{{ $loan?->loan_number ?? 'Unknown' }}</dd>
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

        @if ($canRecord)
            @if ($live)
                <x-card title="Promise to pay">
                    <p class="text-sm">A promise to pay is already live on this case. Automatic reminders are paused until {{ $live->promised_date->format('d M Y') }}.</p>
                </x-card>
            @else
                <x-card title="Record promise">
                    <form method="POST" action="{{ route('lender.collections.promises.store', $case) }}" class="grid gap-4 sm:grid-cols-3 sm:items-end">
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
                    <table class="w-full min-w-[28rem] text-left text-sm">
                        <thead class="text-xs text-muted">
                            <tr>
                                <th class="py-2 pr-4 font-medium">Promised by</th>
                                <th class="py-2 pr-4 font-medium">Amount</th>
                                <th class="py-2 pr-4 font-medium">Outcome</th>
                                <th class="py-2 font-medium">Honored on (EAT)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($case->promisesToPay as $promise)
                                <tr>
                                    <td class="py-3 pr-4 tabular-nums">{{ $promise->promised_date->format('d M Y') }}</td>
                                    <td class="py-3 pr-4 tabular-nums">{{ $money((string) $promise->promised_amount) }}</td>
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