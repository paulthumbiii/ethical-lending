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