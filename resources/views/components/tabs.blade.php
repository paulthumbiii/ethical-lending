@props(['tabs' => [], 'active' => null, 'param' => 'tab'])
<nav {{ $attributes->class('mb-5 flex gap-1 overflow-x-auto border-b border-line') }} aria-label="Sections">
    @foreach ($tabs as $key => $label)
        <a href="{{ request()->fullUrlWithQuery([$param => $key]) }}"
           @if ((string) $active === (string) $key) aria-current="page" @endif
           @class([
               '-mb-px whitespace-nowrap border-b-2 px-4 py-2.5 text-sm',
               'border-brand-700 font-semibold text-brand-700' => (string) $active === (string) $key,
               'border-transparent font-medium text-muted hover:text-ink' => (string) $active !== (string) $key,
           ])>{{ $label }}</a>
    @endforeach
</nav>