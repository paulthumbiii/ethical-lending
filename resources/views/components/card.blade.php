@props(['title' => null])
<div {{ $attributes->class('rounded-lg border border-line bg-white') }}>
    @if ($title)
        <div class="border-b border-line px-5 py-3 text-sm font-semibold">{{ $title }}</div>
    @endif
    <div class="p-5">{{ $slot }}</div>
</div>