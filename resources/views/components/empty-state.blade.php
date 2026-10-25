@props(['title' => 'Nothing here yet', 'text' => null])
<div {{ $attributes->class('px-6 py-12 text-center') }}>
    <p class="text-sm font-semibold">{{ $title }}</p>
    @if ($text)
        <p class="mx-auto mt-1 max-w-sm text-sm text-muted">{{ $text }}</p>
    @endif
    @if (trim((string) $slot) !== '')
        <div class="mt-4">{{ $slot }}</div>
    @endif
</div>