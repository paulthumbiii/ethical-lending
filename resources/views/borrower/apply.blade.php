@php
    $money = fn (string $v): string => 'KES ' . number_format((float) $v, 0);
    $statusText = [
        'submitted' => 'Received',
        'pending' => 'Waiting for review',
        'approved_pending_checker' => 'Checks passed, waiting for final approval',
        'approved' => 'Approved, waiting for payout',
    ];
    $statementTone = fn ($s): string => match ($s?->value) {
        'accepted' => 'success',
        'rejected' => 'danger',
        default => 'warning',
    };

    // Data for the live illustration. Same rules as LoanFeeService::quote().
    $catalog = $products->map(function ($product): array {
        $rule = $product->interestRules->first();
        $fees = [];

        foreach ($product->feeMatrices as $fee) {
            $type = strtolower((string) $fee->calculation_type);

            if ($type === 'percentage') {
                $fees[] = [
                    'name'   => (string) $fee->fee_name,
                    'kind'   => 'pct',
                    'value'  => (string) $fee->fee_percentage,
                    'excise' => (bool) $fee->is_excise_duty_applicable,
                ];
            } elseif ($type === 'fixed' || $type === 'flat') {
                $fees[] = [
                    'name'   => (string) $fee->fee_name,
                    'kind'   => 'fixed',
                    'value'  => (string) $fee->fee_amount,
                    'excise' => (bool) $fee->is_excise_duty_applicable,
                ];
            }
        }

        return [
            'id'   => (string) $product->id,
            'min'  => (int) $product->min_principal,
            'max'  => (int) $product->max_principal,
            'days' => (int) $product->default_tenure_days,
            'rate' => $rule ? (string) $rule->annual_rate : null,
            'fees' => $fees,
        ];
    })->values()->all();
@endphp
<x-layouts.borrower title="Apply">
    <h1 class="text-xl font-semibold">Apply for a loan</h1>

    <div class="mt-4">
        <x-toast />
    </div>

    @if ($openApplication)
        <x-card title="Your application" class="mt-5">
            <dl class="divide-y divide-line text-sm">
                <div class="flex justify-between py-2"><dt class="text-muted">Reference</dt><dd>{{ $openApplication->application_number }}</dd></div>
                <div class="flex justify-between py-2"><dt class="text-muted">Amount</dt><dd class="tabular-nums">{{ $money((string) $openApplication->requested_principal) }}</dd></div>
                <div class="flex justify-between py-2"><dt class="text-muted">Status</dt><dd>{{ $statusText[$openApplication->status] ?? 'In progress' }}</dd></div>
            </dl>
        </x-card>
         <x-application-timeline :application="$openApplication" />

        <x-card title="Your M-Pesa statement" class="mt-5">
            @if ($statement)
                <dl class="divide-y divide-line text-sm">
                    <div class="flex items-center justify-between py-2">
                        <dt class="text-muted">Status</dt>
                        <dd><x-badge tone="{{ $statementTone($statement->review_status) }}">{{ $statement->review_status->borrowerLabel() }}</x-badge></dd>
                    </div>
                    <div class="flex justify-between py-2">
                        <dt class="text-muted">Uploaded</dt>
                        <dd class="tabular-nums">{{ $statement->created_at?->timezone('Africa/Nairobi')->format('d M Y, H:i') }}</dd>
                    </div>
                    @if ($statement->valid_until)
                        <div class="flex justify-between py-2">
                            <dt class="text-muted">Valid until</dt>
                            <dd class="tabular-nums">{{ $statement->valid_until->format('d M Y') }}</dd>
                        </div>
                    @endif
                </dl>

                @if ($statement->review_status->value === 'rejected' && $statement->review_notes)
                    <p class="mt-3 rounded-md border border-rose-300 bg-rose-50 px-3 py-2 text-sm text-rose-800" role="alert">{{ $statement->review_notes }}</p>
                @endif
            @else
                <p class="text-sm text-muted">
                    To assess your application we need your M-Pesa statement for the last 3 months.
                    Request a full statement from Safaricom and upload the PDF exactly as it was sent to you.
                    Please do not edit, screenshot or re-save it: altered statements are not accepted.
                </p>
            @endif

            @if ($canUploadStatement)
                <form method="POST"
                      action="{{ route('borrower.statement.store', ['loanApplication' => $openApplication->id]) }}"
                      enctype="multipart/form-data"
                      class="mt-4 space-y-3 border-t border-line pt-4">
                    @csrf

                    <div>
                        <label for="statement" class="block text-sm font-medium">{{ $statement ? 'Upload a new statement (PDF)' : 'Statement (PDF, up to 5 MB)' }}</label>
                        <input id="statement" name="statement" type="file" accept="application/pdf,.pdf"
                               class="mt-1 block w-full text-sm">
                        @error('statement') <p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="statement_password" class="block text-sm font-medium">Statement password (if it has one)</label>
                        <input id="statement_password" name="statement_password" type="password" autocomplete="off"
                               class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-base focus:border-brand-600 focus:outline-none">
                        <p class="mt-1 text-xs text-muted">Kept encrypted, used only by the credit officer to open your file, and erased once your statement has been checked.</p>
                        @error('statement_password') <p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="flex items-start gap-2 text-sm">
                            <input type="checkbox" name="consent" value="1" class="mt-1 accent-brand-700" @checked(old('consent'))>
                            <span>I agree that this lender may read my M-Pesa statement to assess my loan application. I understand it is handled under the Data Protection Act, 2019.</span>
                        </label>
                        @error('consent') <p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                    </div>

                    <x-button type="submit" class="w-full">Upload statement</x-button>
                </form>
            @endif
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
            <form method="POST" action="{{ route('borrower.apply.store') }}" class="mt-5 space-y-4"
                  x-data="applyQuote({{ \Illuminate\Support\Js::from($catalog) }}, {{ \Illuminate\Support\Js::from((string) old('loan_product_id', $products->first()->id)) }}, {{ \Illuminate\Support\Js::from((string) old('amount', '')) }})">
                @csrf

                @foreach ($products as $product)
                    @php $rate = $product->interestRules->first(); @endphp
                    <label class="block cursor-pointer">
                        <x-card>
                            <div class="flex items-start gap-3">
                                <input type="radio" name="loan_product_id" value="{{ $product->id }}" x-model="pid"
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
                           x-model="amount" x-on:input="amount = amount.replace(/[^0-9]/g, '')"
                           class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-base tabular-nums focus:border-brand-600 focus:outline-none">
                    @error('amount') <p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror

                    <input type="range" aria-label="Move to choose an amount"
                           x-bind:min="product.min" x-bind:max="product.max" step="100"
                           x-bind:value="sliderValue" x-on:input="amount = $event.target.value"
                           class="mt-3 w-full accent-brand-700">
                    <div class="flex justify-between text-xs text-muted tabular-nums">
                        <span x-text="fmtWhole(product.min * 100)"></span>
                        <span x-text="fmtWhole(product.max * 100)"></span>
                    </div>
                </div>

                <div class="rounded-lg border border-line bg-white p-4" aria-live="polite">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-semibold">What it would cost</p>
                        <span class="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-700">Illustration</span>
                    </div>

                    <template x-if="! valid">
                        <p class="mt-3 text-sm text-muted">
                            Enter a whole-shilling amount between <span x-text="fmtWhole(product.min * 100)"></span>
                            and <span x-text="fmtWhole(product.max * 100)"></span> to see how much you would repay.
                        </p>
                    </template>

                    <template x-if="valid">
                        <div>
                            <dl class="mt-3 divide-y divide-line text-sm">
                                <div class="flex justify-between py-2"><dt class="text-muted">You receive</dt><dd class="tabular-nums" x-text="fmt(principalCents)"></dd></div>
                                <template x-if="quote.interestCents !== null">
                                    <div class="flex justify-between py-2"><dt class="text-muted">Interest for <span x-text="product.days"></span> days</dt><dd class="tabular-nums" x-text="fmt(quote.interestCents)"></dd></div>
                                </template>
                                <template x-for="fee in quote.fees" x-bind:key="fee.name">
                                    <div class="flex justify-between gap-3 py-2">
                                        <dt class="text-muted"><span x-text="fee.name"></span><span x-show="fee.excise" class="block text-xs">includes 20% excise duty paid to KRA</span></dt>
                                        <dd class="tabular-nums" x-text="fmt(fee.cents)"></dd>
                                    </div>
                                </template>
                                <div class="flex justify-between py-2 font-semibold"><dt>You would repay</dt><dd class="tabular-nums" x-text="fmt(quote.totalCents)"></dd></div>
                            </dl>
                            <p class="mt-2 text-xs text-muted">This is an illustration. Your exact figures are confirmed after we review your application.</p>
                        </div>
                    </template>
                </div>

                <x-button type="submit" class="w-full">Send application</x-button>
                <p class="text-xs text-muted">We check your details before any decision. Applying does not commit you to take the loan.</p>
            </form>

            <script>
                document.addEventListener('alpine:init', () => {
                    Alpine.data('applyQuote', (catalog, startPid, startAmount) => ({
                        catalog,
                        pid: startPid,
                        amount: startAmount,

                        get product() {
                            return this.catalog.find(p => p.id === String(this.pid)) || this.catalog[0];
                        },
                        get principal() {
                            return this.amount === '' ? NaN : parseInt(this.amount, 10);
                        },
                        get valid() {
                            return Number.isInteger(this.principal) && this.principal >= this.product.min && this.principal <= this.product.max;
                        },
                        get principalCents() {
                            return this.principal * 100;
                        },
                        get sliderValue() {
                            if (! Number.isInteger(this.principal)) return this.product.min;
                            return Math.min(Math.max(this.principal, this.product.min), this.product.max);
                        },

                        // "18.00" -> 1800 (hundredths), so everything below is integer maths.
                        hundredths(text) {
                            const [whole, frac = ''] = String(text).split('.');
                            return parseInt(whole, 10) * 100 + parseInt((frac + '00').slice(0, 2), 10);
                        },
                        // Round half up for non-negative integers: round(num / den).
                        div(num, den) {
                            return Math.floor((2 * num + den) / (2 * den));
                        },

                        get quote() {
                            const p = this.product;
                            const cents = this.principalCents;
                            let interestCents = null;
                            let total = cents;

                            // principal x rate x days / 36,500, same as the contract.
                            if (p.rate !== null) {
                                interestCents = this.div(cents * this.hundredths(p.rate) * p.days, 36500 * 100);
                                total += interestCents;
                            }

                            const fees = [];
                            for (const f of p.fees) {
                                const feeCents = f.kind === 'pct'
                                    ? this.div(cents * this.hundredths(f.value), 10000)
                                    : this.hundredths(f.value);
                                if (feeCents <= 0) continue;
                                fees.push({ name: f.name, excise: f.excise, cents: feeCents });
                                total += feeCents;
                            }

                            return { interestCents, fees, totalCents: total };
                        },

                        fmt(cents) {
                            return 'KES ' + (cents / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        },
                        fmtWhole(cents) {
                            return 'KES ' + (cents / 100).toLocaleString('en-US', { maximumFractionDigits: 0 });
                        },
                    }));
                });
            </script>
        @endif
    @endif
</x-layouts.borrower>