@props(['application', 'latestRequest' => null, 'canRaise' => false])

@php
    $status = (string) $application->status;
    $stamp = fn ($d) => $d?->timezone('Africa/Nairobi')->format('d M Y, H:i');
    $canOpenDesk = (bool) auth()->user()?->hasAnyRole(\App\Enums\UserRole::approvers());
    $requestStatus = $latestRequest?->status;
@endphp

@if ($status === 'approved_pending_checker' || $latestRequest !== null)
    <x-card>
        <h2 class="text-sm font-semibold">Second approver</h2>

        @if ($requestStatus === 'pending')
            <p class="mt-3 text-sm">Waiting for a second approver.</p>
            <p class="mt-1 text-xs text-muted">
                Raised by {{ $latestRequest->maker?->name ?? 'Unknown' }} on {{ $stamp($latestRequest->created_at) }} EAT.
                A different lender admin or finance officer must decide it.
            </p>
            @if ($canOpenDesk)
                <p class="mt-3 text-sm">
                    <a href="{{ route('lender.maker-checker.index') }}" class="text-brand-700 underline-offset-4 hover:underline">Open the review desk</a>
                </p>
            @endif
        @elseif ($requestStatus === 'approved')
            <p class="mt-3 text-sm">Approved by {{ $latestRequest->checker?->name ?? 'Unknown' }} on {{ $stamp($latestRequest->actioned_at) }} EAT.</p>
            @if ($latestRequest->checker_notes)
                <p class="mt-1 text-xs text-muted">Note: {{ $latestRequest->checker_notes }}</p>
            @endif
        @elseif ($requestStatus === 'rejected')
            <p class="mt-3 text-sm">Rejected by {{ $latestRequest->checker?->name ?? 'Unknown' }} on {{ $stamp($latestRequest->actioned_at) }} EAT.</p>
            @if ($latestRequest->checker_notes)
                <p class="mt-1 text-xs text-muted">Reason (staff only): {{ $latestRequest->checker_notes }}</p>
            @endif
        @elseif ($canRaise)
            <p class="mt-3 text-sm text-muted">
                The credit check passed. A second person must confirm before any money can be sent.
                You cannot be the one who approves it.
            </p>
            <form method="POST"
                  action="{{ route('lender.applications.final-approval', ['loanApplication' => $application->id]) }}"
                  class="mt-4"
                  x-on:submit="busy = true">
                @csrf
                <button type="submit" x-bind:disabled="busy"
                        class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60">Send for final approval</button>
            </form>
        @else
            <p class="mt-3 text-sm text-muted">Only credit officers and lender admins can send this application for final approval.</p>
        @endif
    </x-card>
@endif