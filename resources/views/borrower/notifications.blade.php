<x-layouts.borrower title="Alerts">
    <h1 class="text-xl font-semibold">Alerts</h1>

    @if (! $borrower)
        <x-card class="mt-5">
            <p class="text-sm text-muted">Your borrower profile is not linked to this login yet. Contact support to finish setting it up.</p>
        </x-card>
    @else
        @php $unread = $items->whereNull('read_at')->count(); @endphp

        @if ($unread > 0)
            <form method="POST" action="{{ route('borrower.notifications.read') }}" class="mt-3">
                @csrf
                <button type="submit" class="text-sm font-medium text-brand-700 underline-offset-4 hover:underline">Mark all as read</button>
            </form>
        @endif

        @forelse ($items as $item)
            <x-card class="mt-3">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-sm {{ $item->read_at ? 'font-medium' : 'font-semibold' }}">{{ $item->title }}</p>
                    @if (! $item->read_at)
                        <span class="rounded-full bg-brand-600 px-2 py-0.5 text-2xs font-semibold text-white">New</span>
                    @endif
                </div>
                <p class="mt-1 text-sm text-muted">{{ $item->body }}</p>
                <p class="mt-2 text-xs text-muted">{{ $item->created_at?->timezone('Africa/Nairobi')->format('j M Y, H:i') }}</p>
            </x-card>
        @empty
            <x-card class="mt-5">
                <p class="text-sm text-muted">You have no alerts yet. We will tell you here when something changes on your application or loan.</p>
            </x-card>
        @endforelse
    @endif
</x-layouts.borrower>