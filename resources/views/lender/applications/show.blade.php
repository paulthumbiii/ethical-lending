@php
    $pretty = fn (string $v): string => ucwords(strtolower(str_replace('_', ' ', $v)));
    $kes = fn ($v): string => 'KES ' . number_format((float) $v, 2);
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
    $stLabel = fn (string $s): string => match ($s) {
        'awaiting_review' => 'Waiting for your review',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'superseded' => 'Replaced by a newer upload',
        default => $pretty($s),
    };
    $stTone = fn (string $s): string => match ($s) {
        'accepted' => 'success',
        'rejected' => 'danger',
        default => 'warning',
    };
    $flagText = fn (string $f): string => match ($f) {
        'same_file_other_borrower' => 'The exact same file was uploaded by a different borrower.',
        'same_file_earlier_application' => 'This borrower used the exact same file on an earlier application.',
        default => $pretty($f),
    };
    $borrower = $application->borrower;
    $profile = $borrower?->financialProfile;
    $requiredInflow = bcmul((string) $application->requested_principal, '1.50', 2);
    $band = $borrower?->risk_band instanceof \BackedEnum ? $borrower->risk_band->value : $borrower?->risk_band;
@endphp
<x-layouts.lender title="Application {{ $application->application_number }}">
    <div class="space-y-6" x-data="{ busy: false }">
        <p class="text-sm">
            <a href="{{ route('lender.applications.index') }}" class="text-brand-700 underline-offset-4 hover:underline">&larr; Back to applications</a>
        </p>

        @if (session('status'))
            <div role="status" class="rounded-md border border-brand-500 bg-brand-50 px-4 py-3 text-sm text-brand-900">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div role="alert" class="rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ $errors->first() }}
            </div>
        @endif

        <x-card>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold">The request</h2>
                    <p class="mt-1 text-xs tabular-nums text-muted">{{ $application->application_number }}</p>
                </div>
                <x-badge tone="{{ $tone((string) $application->status) }}">{{ $label((string) $application->status) }}</x-badge>
            </div>

            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-xs text-muted">Borrower</dt>
                    <dd class="font-medium">{{ $borrower?->full_name ?? 'Unknown' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">Registered phone</dt>
                    <dd class="font-medium tabular-nums">{{ $borrower?->phone_number ?? 'Unknown' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">Product</dt>
                    <dd class="font-medium">{{ $application->product?->name ?? 'Unknown' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">Amount requested</dt>
                    <dd class="font-medium tabular-nums">{{ $kes($application->requested_principal) }}</dd>
                </div>
            </dl>
        </x-card>

        <x-card>
            <h2 class="text-sm font-semibold">Borrower's money picture</h2>

            @if ($profile === null)
                <p class="mt-3 text-sm text-muted">No financial profile is on file yet. Accepting a statement creates one.</p>
            @else
                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs text-muted">Monthly income</dt>
                        <dd class="font-medium tabular-nums">{{ $kes($profile->monthly_income) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted">Monthly expenses</dt>
                        <dd class="font-medium tabular-nums">{{ $kes($profile->monthly_expenses) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted">Existing debt payments</dt>
                        <dd class="font-medium tabular-nums">{{ $kes($profile->existing_debt_obligations) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted">Verified M-Pesa inflow (monthly)</dt>
                        <dd class="font-medium tabular-nums">{{ $kes($profile->mpesa_average_monthly_inflow) }}</dd>
                    </div>
                </dl>
            @endif

            <p class="mt-4 text-xs text-muted">
                The verified inflow is set when you accept a statement. This request needs at least {{ $kes($requiredInflow) }} (1.5 times the amount requested).
            </p>
        </x-card>

        <x-card>
            <h2 class="text-sm font-semibold">M-Pesa statements</h2>

            @if ($statements->isEmpty())
                <p class="mt-3 text-sm text-muted">The borrower has not uploaded a statement yet.</p>
            @else
                <div class="mt-3 space-y-6">
                    @foreach ($statements as $st)
                        @php
                            $stValue = $st->review_status->value;
                            $stFlags = is_array($st->flags) ? $st->flags : [];
                        @endphp
                        <div class="rounded-md border border-line p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-medium">{{ $st->original_name ?? 'statement.pdf' }}</p>
                                    <p class="text-xs tabular-nums text-muted">
                                        Uploaded {{ $st->created_at?->timezone('Africa/Nairobi')->format('d M Y, H:i') }} EAT
                                        &middot; {{ number_format(((int) $st->file_size) / 1024, 0) }} KB
                                        &middot; fingerprint {{ substr((string) $st->file_sha256, 0, 12) }}
                                    </p>
                                </div>
                                <x-badge tone="{{ $stTone($stValue) }}">{{ $stLabel($stValue) }}</x-badge>
                            </div>

                            @if ($stFlags !== [])
                                <div class="mt-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="alert">
                                    <p class="font-medium">Warning flags (staff only)</p>
                                    <ul class="mt-1 list-disc space-y-0.5 pl-5">
                                        @foreach ($stFlags as $f)
                                            <li>{{ $flagText((string) $f) }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if ($stValue !== 'awaiting_review')
                                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                                    <div>
                                        <dt class="text-xs text-muted">Decided by</dt>
                                        <dd class="font-medium">{{ $st->reviewer?->name ?? 'Not recorded' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs text-muted">When (EAT)</dt>
                                        <dd class="font-medium tabular-nums">{{ $st->reviewed_at?->timezone('Africa/Nairobi')->format('d M Y, H:i') ?? 'n/a' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs text-muted">Valid until</dt>
                                        <dd class="font-medium tabular-nums">{{ $st->valid_until?->format('d M Y') ?? 'n/a' }}</dd>
                                    </div>
                                </dl>
                                @if ($st->review_notes)
                                    <p class="mt-2 text-sm text-muted">{{ $st->review_notes }}</p>
                                @endif
                            @endif

                            @if ($stValue === 'awaiting_review' && $isUnderwriter)
                                <div class="mt-4 flex flex-wrap gap-3">
                                    <a href="{{ route('lender.applications.statements.file', ['mpesaStatementUpload' => $st->id]) }}"
                                       target="_blank" rel="noopener"
                                       class="rounded-md border border-brand-600 px-3 py-1.5 text-sm font-medium text-brand-700 hover:bg-brand-50">Open PDF</a>

                                    <form method="POST" action="{{ route('lender.applications.statements.password', ['mpesaStatementUpload' => $st->id]) }}">
                                        @csrf
                                        <button type="submit"
                                                class="rounded-md border border-line px-3 py-1.5 text-sm font-medium hover:bg-paper">Show password</button>
                                    </form>
                                </div>

                                @if (session('statement_password') && (int) session('statement_password_for') === (int) $st->id)
                                    <p class="mt-3 rounded-md border border-line bg-paper px-3 py-2 text-sm">
                                        Password: <span class="font-mono font-semibold">{{ session('statement_password') }}</span>
                                        <span class="text-xs text-muted">(shown once, and this view was recorded)</span>
                                    </p>
                                @endif

                                @if ($checkable)
                                    <div class="mt-4 rounded-md bg-paper px-3 py-3 text-xs text-muted">
                                        <p class="font-medium text-ink">Before you accept, check the PDF:</p>
                                        <ul class="mt-1 list-disc space-y-0.5 pl-5">
                                            <li>The name and phone match {{ $borrower?->full_name ?? 'the borrower' }} and {{ $borrower?->phone_number ?? 'their registered number' }}.</li>
                                            <li>It covers the last 3 months and was generated recently.</li>
                                            <li>Each balance follows from the one before it, with no gaps or cut-off pages.</li>
                                            <li>Work out the average money received per month, then enter it below.</li>
                                        </ul>
                                    </div>

                                    <div class="mt-4 grid gap-6 lg:grid-cols-2">
                                        <form method="POST"
                                              action="{{ route('lender.applications.statements.accept', ['mpesaStatementUpload' => $st->id]) }}"
                                              class="space-y-2">
                                            @csrf
                                            <label for="inflow-{{ $st->id }}" class="block text-sm font-medium">Verified monthly inflow (KES)</label>
                                            <input id="inflow-{{ $st->id }}" name="verified_inflow" type="text" inputmode="decimal"
                                                   value="{{ old('verified_inflow') }}"
                                                   class="w-full rounded-md border border-line px-3 py-2 text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-brand-500">
                                            @error('verified_inflow') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror

                                            <label for="accept-notes-{{ $st->id }}" class="block text-sm font-medium">
                                                Note {{ $stFlags !== [] ? '(required: this statement has flags)' : '(optional)' }}
                                            </label>
                                            <textarea id="accept-notes-{{ $st->id }}" name="notes" rows="2" maxlength="500"
                                                      class="w-full rounded-md border border-line px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">{{ old('notes') }}</textarea>

                                            <button type="submit"
                                                    class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">Accept statement</button>
                                        </form>

                                        <form method="POST"
                                              action="{{ route('lender.applications.statements.reject', ['mpesaStatementUpload' => $st->id]) }}"
                                              class="space-y-2">
                                            @csrf
                                            <label for="reject-reason-{{ $st->id }}" class="block text-sm font-medium">Reason for rejecting (the borrower will see this)</label>
                                            <textarea id="reject-reason-{{ $st->id }}" name="reason" rows="3" maxlength="500"
                                                      class="w-full rounded-md border border-line px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"></textarea>
                                            @error('reason') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror

                                            <button type="submit"
                                                    class="rounded-md border border-red-600 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50">Reject statement</button>
                                        </form>
                                    </div>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if (! $isUnderwriter && $statements->isNotEmpty())
                <p class="mt-3 text-sm text-muted">Only credit officers and lender admins can open documents and decide on them.</p>
            @endif
        </x-card>

        <x-card>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-sm font-semibold">Credit rules</h2>

                @if ($canAct && $hasAcceptedStatement)
                    <form method="POST"
                          action="{{ route('lender.applications.check', ['loanApplication' => $application->id]) }}"
                          x-on:submit="busy = true">
                        @csrf
                        <button type="submit" x-bind:disabled="busy"
                                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60">Run credit check</button>
                    </form>
                @endif
            </div>

            @if ($checkable && ! $canAct)
                <p class="mt-3 text-sm text-muted">Only credit officers and lender admins can run the check.</p>
            @endif

            @if ($canAct && ! $hasAcceptedStatement)
                <p class="mt-3 text-sm text-muted">Accept a valid M-Pesa statement above before the check can run.</p>
            @endif

            @if ($application->ruleEvaluations->isEmpty())
                <p class="mt-3 text-sm text-muted">
                    @if ($checkable)
                        The check has not been run yet.
                    @else
                        No rule results are on file for this application.
                    @endif
                </p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[36rem] text-left text-sm">
                        <thead class="text-xs text-muted">
                            <tr>
                                <th class="py-2 pr-4 font-medium">Rule</th>
                                <th class="py-2 pr-4 font-medium">Result</th>
                                <th class="py-2 pr-4 font-medium">Actual</th>
                                <th class="py-2 font-medium">Required</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($application->ruleEvaluations as $rule)
                                <tr class="align-top">
                                    <td class="py-3 pr-4">{{ $rule->rule_description }}</td>
                                    <td class="py-3 pr-4">
                                        <x-badge tone="{{ $rule->status === 'PASSED' ? 'success' : 'danger' }}">{{ ucfirst(strtolower((string) $rule->status)) }}</x-badge>
                                    </td>
                                    <td class="py-3 pr-4 tabular-nums">{{ $rule->actual_value }}</td>
                                    <td class="py-3 tabular-nums">{{ $rule->threshold_value }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if (! $checkable)
                <dl class="mt-5 grid gap-4 border-t border-line pt-4 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-xs text-muted">Checked by</dt>
                        <dd class="font-medium">{{ $application->underwriter?->name ?? 'Not recorded' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted">Risk band</dt>
                        <dd class="font-medium">{{ $band ?? 'Not set' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted">Credit limit set</dt>
                        <dd class="font-medium tabular-nums">{{ $kes($borrower?->credit_limit ?? '0') }}</dd>
                    </div>
                </dl>

                @if ($application->rejection_reason)
                    <p class="mt-3 text-sm text-muted">{{ $application->rejection_reason }}</p>
                @endif
            @endif
                    </x-card>

        <x-application-approval-panel :application="$application" :latest-request="$latestApprovalRequest" :can-raise="$canRaiseApproval" />
    </div>
</x-layouts.lender>  