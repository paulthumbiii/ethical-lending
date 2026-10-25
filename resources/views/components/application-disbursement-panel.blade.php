@props([
    'application',
    'loan' => null,
    'disbursement' => null,
    'canDisburse' => false,
    'canSimulate' => false,
])

@php
    $kes = fn ($v): string => 'KES ' . number_format((float) $v, 2);
    $sent = $loan !== null && $loan->disbursed_at !== null;
    $waiting = ! $sent && $disbursement !== null && $disbursement->status === 'initiated';
    $failed = ! $sent && ! $waiting && $disbursement !== null && $disbursement->status === 'failed';

    $feeService = app(\App\Services\Lending\LoanFeeService::class);
    $fees = ($loan !== null && ! $sent) ? $feeService->quote($loan) : [];
    $feeTotal = $sent ? $feeService->chargedTotal($loan) : '0.00';

    $quotedFees = '0.00';
    foreach ($fees as $quotedFee) {
        $quotedFees = bcadd($quotedFees, $quotedFee['amount'], 2);
    }
    $owedAtStart = $loan !== null ? bcadd((string) $loan->principal_disbursed, $quotedFees, 2) : '0.00';
@endphp

@if ($application->status === 'approved' || $loan !== null)
    <x-card>
        <h2 class="text-sm font-semibold">Money out</h2>

        @if ($sent)
            <p class="mt-3 text-sm font-medium text-brand-700">Money sent to the borrower.</p>
            <dl class="mt-3 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-xs text-muted">Loan number</dt>
                    <dd class="font-medium tabular-nums">{{ $loan->loan_number }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">Amount sent</dt>
                    <dd class="font-medium tabular-nums">{{ $kes($loan->principal_disbursed) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">Sent on (EAT)</dt>
                    <dd class="font-medium tabular-nums">{{ $loan->disbursed_at->timezone('Africa/Nairobi')->format('d M Y, H:i') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">M-Pesa receipt</dt>
                    <dd class="font-medium tabular-nums">{{ $disbursement?->transaction_id ?? 'Not recorded' }}</dd>
                </div>
            </dl>
            @if (bccomp($feeTotal, '0.00', 2) > 0)
                <p class="mt-3 text-sm">Fees added to the balance: <span class="font-medium tabular-nums">{{ $kes($feeTotal) }}</span></p>
            @endif
            <p class="mt-3 text-xs text-muted">Repayment is due by {{ $loan->due_date?->timezone('Africa/Nairobi')->format('d M Y') ?? 'n/a' }}.</p>
        @elseif ($waiting)
            <p class="mt-3 text-sm text-muted">
                The payout of {{ $kes($loan->principal_disbursed) }} was sent to M-Pesa. We are waiting for Safaricom to confirm it.
                Another payout cannot be started until this one is resolved.
            </p>

            @if ($canSimulate)
                <form method="POST"
                      action="{{ route('lender.applications.simulate-payout', ['loanApplication' => $application->id]) }}"
                      class="mt-4"
                      x-on:submit="busy = true">
                    @csrf
                    <button type="submit" x-bind:disabled="busy"
                            class="rounded-md border border-brand-600 px-4 py-2 text-sm font-medium text-brand-700 hover:bg-brand-50 disabled:opacity-60">Simulate M-Pesa result</button>
                    <p class="mt-2 text-xs text-muted">Local demo only. This pretends Safaricom confirmed the payout. It is not available in production.</p>
                </form>
            @endif
        @else
            @if ($loan !== null)
                <p class="mt-3 text-sm text-muted">
                    Loan {{ $loan->loan_number }} is ready: {{ $kes($loan->principal_disbursed) }} waiting to be sent.
                </p>
            @else
                <p class="mt-3 text-sm text-muted">
                    This application is approved. The loan is created from the approved amount, capped at the borrower's credit limit, in whole shillings.
                </p>
            @endif

            @if ($fees !== [])
                <div class="mt-3 rounded-md bg-paper px-3 py-3 text-sm">
                    <p class="font-medium">Charges added when the money lands</p>
                    <ul class="mt-1 space-y-0.5">
                        @foreach ($fees as $fee)
                            <li class="flex justify-between gap-4">
                                <span>{{ $fee['name'] }}@if ($fee['excise']) <span class="text-xs text-muted">(includes 20% excise duty)</span>@endif</span>
                                <span class="tabular-nums">{{ $kes($fee['amount']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs text-muted">
                        The borrower receives {{ $kes($loan->principal_disbursed) }} and will owe {{ $kes($owedAtStart) }} in total.
                    </p>
                </div>
            @endif

            @if ($failed)
                <p class="mt-3 rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">
                    The last payout attempt failed: {{ $disbursement->response_description }}. Nothing was sent.
                </p>
            @endif

            @if ($canDisburse)
                <form method="POST"
                      action="{{ route('lender.applications.disburse', ['loanApplication' => $application->id]) }}"
                      class="mt-4"
                      x-on:submit="busy = true">
                    @csrf
                    <button type="submit" x-bind:disabled="busy"
                            class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60">
                        {{ $loan === null ? 'Create loan and send money' : 'Send money' }}
                    </button>
                </form>
            @else
                <p class="mt-3 text-sm text-muted">Only finance officers and lender admins can send money.</p>
            @endif
        @endif
    </x-card>
@endif