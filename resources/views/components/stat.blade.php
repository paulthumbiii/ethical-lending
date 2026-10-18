@props(['label', 'value', 'hint' => null])
<div {{ $attributes->class('px-5 py-4') }}>
    <p class="text-sm text-muted">{{ $label }}</p>
    <p class="mt-1 text-3xl font-semibold tabular-nums">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-muted">{{ $hint }}</p>
    @endif
</div>