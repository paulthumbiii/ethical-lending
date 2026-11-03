{{-- resources/views/components/layouts/borrower.blade.php --}}
@props(['title' => 'Home'])
@php
    $unreadAlerts = \App\Models\BorrowerNotification::unreadCountFor(auth()->user()?->borrower?->id);
    $links = [
        ['label' => 'Home', 'route' => 'borrower.dashboard', 'match' => 'borrower.dashboard'],
        ['label' => 'Apply', 'route' => 'borrower.apply.create', 'match' => 'borrower.apply.*'],
        ['label' => 'Account', 'route' => 'borrower.account', 'match' => 'borrower.account'],
        ['label' => 'Alerts', 'route' => 'borrower.notifications', 'match' => 'borrower.notifications*'],
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title }} | {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-paper font-sans text-ink antialiased">
    <header class="sticky top-0 z-30 border-b border-line bg-white pt-[env(safe-area-inset-top)]">
        <div class="mx-auto flex h-14 max-w-5xl items-center gap-4 px-4">
            <a href="{{ route('borrower.dashboard') }}" class="flex items-center gap-2.5">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-900 text-xs font-bold text-accent-400" aria-hidden="true">EL</span>
                <span class="text-sm font-semibold text-brand-900">{{ config('app.name') }}</span>
            </a>

            <nav class="fixed inset-x-0 bottom-0 z-30 flex items-stretch border-t border-line bg-white pb-[env(safe-area-inset-bottom)] md:static md:ml-4 md:flex-1 md:items-center md:gap-1 md:border-0 md:bg-transparent md:pb-0" aria-label="Main">
                @foreach ($links as $link)
                    @php $isActive = request()->routeIs($link['match']); @endphp
                    <a href="{{ route($link['route']) }}"
                       @if ($isActive) aria-current="page" @endif
                       @class([
                           'flex flex-1 items-center justify-center py-3.5 text-sm transition-colors motion-reduce:transition-none md:flex-none md:rounded-md md:px-3 md:py-2',
                           'font-semibold text-brand-700 md:bg-brand-50' => $isActive,
                           'font-medium text-muted hover:text-ink' => ! $isActive,
                       ])>{{ $link['label'] }}
                        @if ($link['label'] === 'Alerts' && $unreadAlerts > 0)
                            <span class="ml-1.5 rounded-full bg-brand-700 px-1.5 py-0.5 text-2xs font-semibold text-white">{{ $unreadAlerts }}</span>
                        @endif
                    </a>
                @endforeach

                <span class="hidden px-3 text-sm text-muted md:ml-auto md:inline">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}" class="flex flex-1 md:flex-none">
                    @csrf
                    <button type="submit" class="flex-1 py-3.5 text-sm font-medium text-muted hover:text-ink md:rounded-md md:px-3 md:py-2">Sign out</button>
                </form>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-3xl px-4 pb-28 pt-6 md:pb-12">
        {{ $slot }}
    </main>
</body>
</html>