@props(['action' => null])
@php
    $target = $action ?? url()->current();
@endphp
<form method="GET" action="{{ $target }}" {{ $attributes->class('mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-line bg-white p-4') }}>
    {{ $slot }}
    <div class="flex items-center gap-2">
        <x-button type="submit">Apply</x-button>
        <a href="{{ $target }}" class="text-sm font-medium text-muted underline-offset-4 hover:text-ink hover:underline">Reset</a>
    </div>
</form>