{{-- resources/views/components/button.blade.php --}}
@props(['variant' => 'primary', 'type' => 'button'])
@php
    $styles = [
        'primary' => 'bg-brand-900 text-white hover:bg-brand-700 focus-visible:outline-brand-700',
        'secondary' => 'border border-line bg-white text-ink hover:bg-paper focus-visible:outline-brand-700',
    ];
@endphp
<button type="{{ $type }}" {{ $attributes->class(['inline-flex items-center justify-center rounded-md px-4 py-2.5 text-sm font-semibold transition-colors motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-wait disabled:opacity-60', $styles[$variant] ?? $styles['primary']]) }}>{{ $slot }}</button>