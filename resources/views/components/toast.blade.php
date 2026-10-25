@php
    $message = session('status');
    $isError = false;
    if (! is_string($message) || $message === '') {
        $message = session('error');
        $isError = is_string($message) && $message !== '';
    }
@endphp
@if (is_string($message) && $message !== '')
    <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 6000)" x-show="show" x-cloak
         role="{{ $isError ? 'alert' : 'status' }}"
         @class([
             'mb-4 flex items-start justify-between gap-3 rounded-md border px-4 py-3 text-sm',
             'border-rose-200 bg-rose-50 text-rose-800' => $isError,
             'border-emerald-200 bg-emerald-50 text-emerald-800' => ! $isError,
         ])>
        <p>{{ $message }}</p>
        <button type="button" class="font-medium underline-offset-4 hover:underline" x-on:click="show = false">Dismiss</button>
    </div>
@endif