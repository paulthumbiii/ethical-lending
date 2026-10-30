@php
    $money = fn (string $v): string => 'KES ' . number_format((float) $v, 2);
@endphp
<x-layouts.borrower title="Home">
    <h1 class="text-xl font-semibold">Hello, {{ \Illuminate\Support\Str::of(auth()->user()->name)->before(' ') }}</h1>

    @if (session('status'))
        <div class="mt-4 rounded-lg border border-brand-500 bg-brand-50 px-4 py-3 text-sm text-brand-900" role="status">{{ session('status') }}</div>
    @endif

    @error('relief')
        <div class="mt-4 rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{{ $message }}</div>
    @enderror

    @error('promise')
        <div class="mt-4 rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{{ $message }}</div>
    @enderror

    @if (! $borrower)
        <x-card class="mt-5">
            <p class="text-sm text-muted">Your borrower profile is not linked to this login yet. Contact support to finish setting it up.</p>
        </x-card>
    @elseif ($portal['loan'] === null)
        <x-card title="Your credit limit" class="mt-5">
            <p class="text-3xl font-semibold tabular-nums">{{ $money((string) $borrower->credit_limit) }}</p>
        </x-card>
        <x-card class="mt-4">
            <p class="text-sm text-muted">You have no active loan right now.</p>
        </x-card>
    @else
        @php
            $loan = $portal['loan'];
            $tone = match ($loan->status->value) {
                'defaulted' => 'danger',
                'pending', 'approval_hold' => 'warning',
                'restructured' => 'success',
                default => 'info',
            };
        @endphp

        <x-card class="mt-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-muted">Loan {{ $loan->loan_number }}</p>
                    <p class="mt-1 text-3xl font-semibold tabular-nums">{{ $money($portal['owed']) }}</p>
                    <p class="text-sm text-muted">you owe in total</p>
                </div>
                <x-badge :tone="$tone">{{ \Illuminate\Support\Str::headline($loan->status->value) }}</x-badge>
            </div>

            <dl class="mt-4 divide-y divide-line text-sm">
                <div class="flex justify-between py-2"><dt class="text-muted">Principal</dt><dd class="tabular-nums">{{ $money($portal['balances']['principal']) }}</dd></div>
                <div class="flex justify-between py-2"><dt class="text-muted">Interest</dt><dd class="tabular-nums">{{ $money($portal['balances']['interest']) }}</dd></div>
                <div class="flex justify-between py-2"><dt class="text-muted">Penalties and fees</dt><dd class="tabular-nums">{{ $money($portal['balances']['fees']) }}</dd></div>
                <div class="flex items-center justify-between py-2">
                    <dt class="text-muted">Due date</dt>
                    <dd class="flex items-center gap-2">
                        <span>{{ $portal['due_date'] ?? 'Not set' }}</span>
                        @if ($portal['is_overdue'])
                            <x-badge tone="danger">Overdue</x-badge>
                        @endif
                    </dd>
                </div>
            </dl>
        </x-card>
        @php
            $ceiling = (int) bcadd($portal['owed'], '0.99', 0);
        @endphp
        @if (in_array($loan->status->value, ['disbursed', 'defaulted', 'restructured'], true) && $ceiling >= 1)
            <x-repayment-form :loan="$loan" :ceiling="$ceiling" :phone="(string) $borrower->phone_number" class="mt-5" />
        @endif

        @if ($promise)
            <x-card title="Tell us when you will pay" class="mt-5">
                @if ($promise['live'])
                    <p class="text-sm">You promised {{ $money((string) $promise['live']->promised_amount) }} by {{ $promise['live']->promised_date->format('j M Y') }}. We will not send reminders until then.</p>
                    <p class="mt-2 text-xs text-muted">If the payment has not arrived by that date, reminders start again. A promise does not change what you owe.</p>
                @else
                    <p class="text-sm text-muted">Your payment is late. Tell us how much you can pay and by when, and we will not send reminders until that date. Pick a date within the next {{ $promise['max_days'] }} days.</p>
                    <form method="POST" action="{{ route('borrower.promise.store', $loan) }}" class="mt-4 space-y-4">
                        @csrf
                        <x-input label="Amount you can pay (KES)" name="amount" type="text" inputmode="decimal" :value="old('amount')" />
                        <x-input label="Date you will pay" name="promised_date" type="date" :value="old('promised_date')" min="{{ $promise['min'] }}" max="{{ $promise['max'] }}" />
                        <x-button type="submit" class="w-full">Save my promise</x-button>
                        <p class="text-xs text-muted">A promise does not change what you owe. You can pay any time with M-Pesa above.</p>
                    </form>
                @endif
            </x-card>
        @endif

        <x-in-duplum-meter :meter="$portal['meter']" class="mt-5" />

        @if ($relief = $portal['relief'])
            <x-card title="Finding it hard to pay?" class="mt-5">
                @if (! $relief['eligible'])
                    <p class="text-sm text-muted">We cannot offer a relief plan online right now. Please contact support and we will look at your options with you.</p>
                @else
                    <div x-data="{
                            options: {{ \Illuminate\Support\Js::from($relief['options']) }},
                            plans: {{ \Illuminate\Support\Js::from($relief['schedule']) }},
                            idx: {{ (int) array_search($relief['default_days'], $relief['options'], true) }},
                            get days() { return this.options[this.idx]; }
                        }">
                        <p class="text-sm text-muted">If you cannot pay in full, you can ask for more time. Penalties and fees are removed and you pay what is left in two parts.</p>

                        <dl class="mt-4 divide-y divide-line text-sm">
                            <div class="flex justify-between py-2"><dt class="text-muted">You owe today</dt><dd class="tabular-nums">{{ $money($relief['owed_today']) }}</dd></div>
                            <div class="flex justify-between py-2"><dt class="text-muted">Penalties and fees removed</dt><dd class="tabular-nums text-emerald-700">- {{ $money($relief['waived']) }}</dd></div>
                            <div class="flex justify-between py-2 font-semibold"><dt>You would owe after relief</dt><dd class="tabular-nums">{{ $money($relief['owed_after']) }}</dd></div>
                        </dl>

                        <label for="relief-days" class="mt-5 block text-sm font-medium">
                            Time to pay: <span x-text="days"></span> days
                        </label>
                        <input id="relief-days" type="range" min="0" max="{{ count($relief['options']) - 1 }}" step="1"
                               x-model.number="idx" class="mt-2 w-full accent-brand-700">

                        <ol class="mt-4 space-y-2 text-sm">
                            <li class="flex justify-between gap-3">
                                <span>Payment 1 by <span class="font-medium" x-text="plans[days].first_due"></span></span>
                                <span class="tabular-nums">{{ $money($relief['first_amount']) }}</span>
                            </li>
                            <li class="flex justify-between gap-3">
                                <span>Payment 2 by <span class="font-medium" x-text="plans[days].second_due"></span></span>
                                <span class="tabular-nums">{{ $money($relief['second_amount']) }}</span>
                            </li>
                        </ol>

                        <form method="POST" action="{{ route('borrower.restructuring.accept', $loan) }}" class="mt-5">
                            @csrf
                            <input type="hidden" name="tenure_extension_days" value="{{ $relief['default_days'] }}" x-bind:value="days">
                            <x-button type="submit" class="w-full">Accept relief plan</x-button>
                            <p class="mt-2 text-xs text-muted">Accepting changes your payment plan as shown above. It is recorded on your account.</p>
                        </form>
                    </div>
                @endif
            </x-card>
        @endif
    @endif
</x-layouts.borrower>