@props(['tone' => 'neutral'])
@php
    $tones = [
        'neutral' => 'bg-slate-100 text-slate-700',
        'success' => 'bg-emerald-50 text-emerald-800',
        'warning' => 'bg-amber-50 text-amber-800',
        'danger' => 'bg-rose-50 text-rose-700',
        'info' => 'bg-sky-50 text-sky-800',
    ];
@endphp
<span {{ $attributes->class(['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium', $tones[$tone] ?? $tones['neutral']]) }}>{{ $slot }}</span>