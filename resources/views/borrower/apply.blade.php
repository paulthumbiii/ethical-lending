@php
    $money = fn (string $v): string => 'KES ' . number_format((float) $v, 0);
    $statusText = [
        'submitted' => 'Received',
        'pending' => 'Waiting for review',
        'approved_pending_checker' => 'Checks passed, waiting for final approval',
        'approved' => 'Approved, waiting for payout',
    ];
@endphp
<x-layouts.borrower title="Apply">
    <h1 class="text-xl font-semibold">Apply for a loan</h1>

    @if (session('status'))
        <div class="mt-4 rounded-lg border border-brand-500 bg-brand-50 px-4 py-3 text-sm text-brand-900" role="status">{{ session('status') }}</div>
    @endif

    @if ($openApplication)
        <x-card title="Your application" class="mt-5">
            <dl class="divide-y divide-line text-sm">
                <div class="flex justify-between py-2"><dt class="text-muted">Reference</dt><dd>{{ $openApplication->application_number }}</dd></div>
                <div class="flex justify-between py-2"><dt class="text-muted">Amount</dt><dd class="tabular-nums">{{ $money((string) $openApplication->requested_principal) }}</dd></div>
                <div class="flex justify-between py-2"><dt class="text-muted">Status</dt><dd>{{ $statusText[$openApplication->status] ?? 'In progress' }}</dd></div>
            </dl>
        </x-card>
    @elseif ($hasActiveLoan)
        <x-card class="mt-5">
            <p class="text-sm text-muted">You have a loan that is not fully repaid yet. You can apply again once it is settled.</p>
        </x-card>
    @else
        @if ($lastRejected)
            <div class="mt-4 rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">
                Your last application was not approved{{ $lastRejected->rejection_reason ? ': ' . $lastRejected->rejection_reason : '.' }}
            </div>
        @endif

        @if ($products->isEmpty())
            <x-card class="mt-5">
                <p class="text-sm text-muted">No loan products are open right now. Please check back soon.</p>
            </x-card>
        @else
            <form method="POST" action="{{ route('borrower.apply.store') }}" class="mt-5 space-y-4">
                @csrf

                @foreach ($products as $product)
                    @php $rate = $product->interestRules->first(); @endphp
                    <label class="block cursor-pointer">
                        <x-card>
                            <div class="flex items-start gap-3">
                                <input type="radio" name="loan_product_id" value="{{ $product->id }}"
                                       class="mt-1 accent-brand-700" @checked((int) old('loan_product_id', $products->first()->id) === $product->id)>
                                <div class="flex-1">
                                    <p class="font-semibold">{{ $product->name }}</p>
                                    <p class="text-sm text-muted">{{ $money((string) $product->min_principal) }} to {{ $money((string) $product->max_principal) }} over {{ $product->default_tenure_days }} days</p>
                                    <ul class="mt-2 space-y-1 text-sm">
                                        @if ($rate)
                                            <li>Interest: {{ rtrim(rtrim((string) $rate->annual_rate, '0'), '.') }}% a year</li>
                                        @endif
                                        @foreach ($product->feeMatrices as $fee)
                                            <li>
                                                {{ $fee->fee_name }}:
                                                @if ((float) $fee->fee_percentage > 0)
                                                    {{ rtrim(rtrim((string) $fee->fee_percentage, '0'), '.') }}% of the loan
                                                @else
                                                    {{ $money((string) $fee->fee_amount) }}
                                                @endif
                                                @if ($fee->is_excise_duty_applicable)
                                                    <span class="text-muted">(includes 20% excise duty paid to KRA)</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        </x-card>
                    </label>
                @endforeach
                @error('loan_product_id') <p class="text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror

                <div>
                    <label for="amount" class="block text-sm font-medium">How much do you need? (KES)</label>
                    <input id="amount" name="amount" type="text" inputmode="numeric" autocomplete="off"
                           value="{{ old('amount') }}"
                           class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-base tabular-nums focus:border-brand-600 focus:outline-none">
                    @error('amount') <p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                </div>

                <x-button type="submit" class="w-full">Send application</x-button>
                <p class="text-xs text-muted">We check your details before any decision. Applying does not commit you to take the loan.</p>
            </form>
        @endif
    @endif
</x-layouts.borrower>