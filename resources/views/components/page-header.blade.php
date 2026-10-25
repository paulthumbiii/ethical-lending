@props(['subtitle' => null])
<div {{ $attributes->class('mb-6 flex flex-wrap items-start justify-between gap-3') }}>
    <div>
        @if ($subtitle)
            <p class="text-sm text-muted">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>