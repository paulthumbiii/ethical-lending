@props(['headings' => [], 'isEmpty' => false, 'emptyTitle' => 'Nothing here yet', 'emptyText' => null])
<div {{ $attributes->class('overflow-hidden rounded-lg border border-line bg-white') }}>
    @if ($isEmpty)
        <x-empty-state :title="$emptyTitle" :text="$emptyText" />
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-paper">
                    <tr>
                        @foreach ($headings as $heading)
                            <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    {{ $slot }}
                </tbody>
            </table>
        </div>
    @endif
</div>