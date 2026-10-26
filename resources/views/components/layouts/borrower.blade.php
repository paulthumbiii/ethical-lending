@props(['title' => 'Home'])
@php
    $unreadAlerts = \App\Models\BorrowerNotification::unreadCountFor(auth()->user()?->borrower?->id);
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
    <header class="border-b border-line bg-white px-4 pb-3 pt-[calc(0.75rem+env(safe-area-inset-top))]">
        <div class="mx-auto flex max-w-md items-center justify-between">
            <span class="text-sm font-semibold text-brand-700">{{ config('app.name') }}</span>
            <div class="flex items-center gap-3">
                <a href="{{ route('borrower.notifications') }}" class="text-sm font-medium text-brand-700 underline-offset-4 hover:underline">Alerts
                    @if ($unreadAlerts > 0)
                        <span class="ml-1 rounded-full bg-brand-600 px-1.5 py-0.5 text-2xs font-semibold text-white">{{ $unreadAlerts }}</span>
                    @endif
                </a>
                <span class="text-sm text-muted">{{ auth()->user()->name }}</span>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-md px-4 pb-28 pt-6">
        {{ $slot }}
    </main>

    <nav class="fixed inset-x-0 bottom-0 z-20 border-t border-line bg-white pb-[env(safe-area-inset-bottom)]" aria-label="Main">
        <div class="mx-auto flex max-w-md items-stretch">
            <a href="{{ route('borrower.dashboard') }}"
               @if (request()->routeIs('borrower.dashboard')) aria-current="page" @endif
               @class([
                   'flex flex-1 items-center justify-center py-3.5 text-sm',
                   'font-semibold text-brand-700' => request()->routeIs('borrower.dashboard'),
                   'font-medium text-muted' => ! request()->routeIs('borrower.dashboard'),
               ])>Home</a>
            <a href="{{ route('borrower.apply.create') }}"
               @if (request()->routeIs('borrower.apply.*')) aria-current="page" @endif
               @class([
                   'flex flex-1 items-center justify-center py-3.5 text-sm',
                   'font-semibold text-brand-700' => request()->routeIs('borrower.apply.*'),
                   'font-medium text-muted' => ! request()->routeIs('borrower.apply.*'),
               ])>Apply</a>
            <a href="{{ route('borrower.account') }}"
               @if (request()->routeIs('borrower.account')) aria-current="page" @endif
               @class([
                   'flex flex-1 items-center justify-center py-3.5 text-sm',
                   'font-semibold text-brand-700' => request()->routeIs('borrower.account'),
                   'font-medium text-muted' => ! request()->routeIs('borrower.account'),
               ])>Account</a>
            <form method="POST" action="{{ route('logout') }}" class="flex flex-1">
                @csrf
                <button type="submit" class="flex-1 py-3.5 text-sm font-medium text-muted">Sign out</button>
            </form>
        </div>
    </nav>
</body>
</html>