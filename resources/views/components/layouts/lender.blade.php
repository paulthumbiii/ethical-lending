@props(['title' => 'Dashboard'])
@php
    $user = auth()->user();
    $roleLabel = \Illuminate\Support\Str::headline((string) $user->getRoleNames()->first());
    $nav = [
        ['label' => 'Dashboard', 'route' => 'lender.dashboard', 'roles' => null],
        ['label' => 'Review desk', 'route' => 'lender.maker-checker.index', 'roles' => \App\Enums\UserRole::approvers()],
        ['label' => 'Collections', 'route' => 'lender.collections.index', 'roles' => \App\Enums\UserRole::collectionsViewers()],
    ];
    $nav = array_values(array_filter(
        $nav,
        fn (array $item): bool => ($item['roles'] ?? null) === null || $user->hasAnyRole($item['roles'])
    ));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-paper font-sans text-ink antialiased" x-data="{ open: false }">
    <div x-show="open" x-cloak x-on:click="open = false" class="fixed inset-0 z-20 bg-ink/50 lg:hidden"></div>

    <aside class="fixed inset-y-0 left-0 z-30 flex w-64 flex-col bg-brand-900 text-brand-100 transition-transform duration-200 motion-reduce:transition-none lg:translate-x-0"
           x-bind:class="open ? 'translate-x-0' : '-translate-x-full'">
        <div class="px-6 py-5 text-lg font-semibold text-white">{{ config('app.name') }}</div>

        <nav class="flex-1 space-y-1 px-3" aria-label="Main">
            @foreach ($nav as $item)
                <a href="{{ route($item['route']) }}"
                   @if (request()->routeIs($item['route'])) aria-current="page" @endif
                   @class([
                       'flex items-center rounded-md px-3 py-2 text-sm font-medium',
                       'bg-white/10 text-white' => request()->routeIs($item['route']),
                       'hover:bg-white/5' => ! request()->routeIs($item['route']),
                   ])>{{ $item['label'] }}</a>
            @endforeach
        </nav>

        <div class="border-t border-white/10 px-6 py-4">
            <p class="truncate text-sm font-medium text-white">{{ $user->name }}</p>
            <p class="text-xs">{{ $roleLabel }}</p>
            <form method="POST" action="{{ route('logout') }}" class="mt-3">
                @csrf
                <button type="submit" class="text-sm underline-offset-4 hover:text-white hover:underline">Sign out</button>
            </form>
        </div>
    </aside>

    <div class="lg:pl-64">
        <header class="sticky top-0 z-10 flex h-14 items-center gap-3 border-b border-line bg-paper/90 px-4 backdrop-blur sm:px-8">
            <button type="button" class="-ml-1 rounded-md p-2 lg:hidden" x-on:click="open = true" aria-label="Open menu">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 5h14M3 10h14M3 15h14"/></svg>
            </button>
            <h1 class="text-base font-semibold">{{ $title }}</h1>
        </header>

        <main class="px-4 py-6 sm:px-8">
            {{ $slot }}
        </main>
    </div>
</body>
</html>