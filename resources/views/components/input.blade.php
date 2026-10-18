@props(['label', 'name', 'type' => 'text'])
<div>
    <label for="{{ $name }}" class="mb-1.5 block text-sm font-medium">{{ $label }}</label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}"
        {{ $attributes->class(['block w-full rounded-md border bg-white px-3.5 py-2.5 text-sm focus:outline-2 focus:outline-brand-600', $errors->has($name) ? 'border-rose-500' : 'border-line']) }}>
    @error($name)
        <p class="mt-1.5 text-sm text-rose-700">{{ $message }}</p>
    @enderror
</div>