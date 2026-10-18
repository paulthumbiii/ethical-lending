@props(['title' => 'Home'])
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
            <span class="text-sm text-muted">{{ auth()->user()->name }}</span>
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
            <form method="POST" action="{{ route('logout') }}" class="flex flex-1">
                @csrf
                <button type="submit" class="flex-1 py-3.5 text-sm font-medium text-muted">Sign out</button>
            </form>
        </div>
    </nav>
</body>
</html>