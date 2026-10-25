@props(['status'])
@php
    $key = strtolower((string) $status);
    $tone = match (true) {
        in_array($key, ['disbursed', 'settled', 'approved', 'accepted', 'verified', 'successful', 'active', 'paid'], true) => 'success',
        in_array($key, ['defaulted', 'rejected', 'failed', 'written_off', 'blacklisted', 'frozen'], true) => 'danger',
        in_array($key, ['pending', 'submitted', 'approval_hold', 'approved_pending_checker', 'awaiting_review', 'initiated', 'restructured'], true) => 'warning',
        default => 'neutral',
    };
    $label = ucwords(strtolower(str_replace('_', ' ', (string) $status)));
@endphp
<x-badge :tone="$tone" {{ $attributes }}>{{ $label }}</x-badge>