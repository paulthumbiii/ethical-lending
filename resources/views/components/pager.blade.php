@props(['paginator'])
@if ($paginator->total() > 0)
    <div {{ $attributes->class('flex flex-wrap items-center justify-between gap-3 px-1 py-3 text-sm text-muted') }}>
        <p>Showing {{ $paginator->firstItem() }} to {{ $paginator->lastItem() }} of {{ $paginator->total() }}</p>
        <div class="flex gap-2">
            @if ($paginator->onFirstPage())
                <span class="rounded-md border border-line px-3 py-1.5 opacity-50">Previous</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" class="rounded-md border border-line bg-white px-3 py-1.5 font-medium text-ink hover:bg-paper">Previous</a>
            @endif
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" class="rounded-md border border-line bg-white px-3 py-1.5 font-medium text-ink hover:bg-paper">Next</a>
            @else
                <span class="rounded-md border border-line px-3 py-1.5 opacity-50">Next</span>
            @endif
        </div>
    </div>
@endif