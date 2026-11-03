{{-- resources/views/components/layouts/lender.blade.php --}}
@props(['title' => 'Dashboard'])
@php
    $user = auth()->user();
    $roleLabel = \Illuminate\Support\Str::headline((string) $user->getRoleNames()->first());
    $nav = [
        ['label' => 'Dashboard', 'route' => 'lender.dashboard', 'roles' => null],
        ['label' => 'Borrowers', 'route' => 'lender.borrowers.index', 'roles' => null, 'permission' => \App\Enums\StaffPermission::BorrowersView->value],
        ['label' => 'Loans', 'route' => 'lender.loans.index', 'roles' => null, 'permission' => \App\Enums\StaffPermission::LoansView->value],
        ['label' => 'Finance', 'route' => 'lender.finance.index', 'roles' => null, 'permission' => 'finance.view'],
        ['label' => 'Audit', 'route' => 'lender.audit.index', 'roles' => null, 'permission' => 'audit.view'],
        ['label' => 'Applications', 'route' => 'lender.applications.index', 'roles' => \App\Enums\UserRole::applicationViewers()],
        ['label' => 'Review desk', 'route' => 'lender.maker-checker.index', 'roles' => \App\Enums\UserRole::approvers()],
        ['label' => 'Collections', 'route' => 'lender.collections.index', 'roles' => \App\Enums\UserRole::collectionsViewers()],
    ];
    $nav = array_values(array_filter(
        $nav,
        fn (array $item): bool => (($item['roles'] ?? null) === null || $user->hasAnyRole($item['roles']))
            && (($item['permission'] ?? null) === null || $user->can($item['permission']))
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
<body class="bg-paper font-sans text-ink antialiased" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
    <div x-show="open" x-cloak x-on:click="open = false" class="fixed inset-0 z-20 bg-ink/50 lg:hidden"></div>

    <aside class="fixed inset-y-0 left-0 z-30 flex w-64 flex-col bg-brand-900 text-brand-100 transition-transform duration-200 motion-reduce:transition-none lg:translate-x-0"
           x-bind:class="open ? 'translate-x-0' : '-translate-x-full'">
        <div class="flex items-center gap-3 px-6 py-5">
            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-accent-400 text-xs font-bold text-brand-900" aria-hidden="true">EL</span>
            <span class="text-base font-semibold text-white">{{ config('app.name') }}</span>
        </div>

        <nav class="flex-1 space-y-1 px-3" aria-label="Main">
            @foreach ($nav as $item)
                @php
                    $prefix = str_ends_with($item['route'], '.index')
                        ? \Illuminate\Support\Str::beforeLast($item['route'], '.') . '.*'
                        : $item['route'];
                    $isActive = request()->routeIs($item['route'], $prefix);
                @endphp
                <a href="{{ route($item['route']) }}"
                   @if ($isActive) aria-current="page" @endif
                   @class([
                       'relative flex items-center rounded-md px-3 py-2 text-sm font-medium transition-colors motion-reduce:transition-none',
                       'bg-white/10 text-white before:absolute before:inset-y-2 before:left-0 before:w-0.5 before:rounded before:bg-accent-400' => $isActive,
                       'hover:bg-white/5 hover:text-white' => ! $isActive,
                   ])>{{ $item['label'] }}</a>
            @endforeach
        </nav>

        <div class="border-t border-white/10 px-6 py-4">
            <div class="flex items-center gap-3">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/10 text-xs font-semibold text-white" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $user->name, 0, 1)) }}</span>
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-white">{{ $user->name }}</p>
                    <p class="text-xs">{{ $roleLabel }}</p>
                </div>
            </div>
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

            <div class="ml-auto hidden items-center gap-2 text-xs sm:flex" x-cloak
                 x-data="{ time: '', hoursOpen: false, tick() { const d = new Date(); this.time = d.toLocaleTimeString('en-GB', { timeZone: 'Africa/Nairobi', hour: '2-digit', minute: '2-digit' }); const h = parseInt(d.toLocaleString('en-GB', { timeZone: 'Africa/Nairobi', hour: '2-digit', hour12: false }), 10); this.hoursOpen = h >= 8 && h < 18; } }"
                 x-init="tick(); setInterval(() => tick(), 30000)">
                <span class="tabular-nums text-muted" x-text="time + ' EAT'"></span>
                <span class="rounded-full px-2.5 py-0.5 font-medium"
                      x-bind:class="hoursOpen ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800'"
                      x-text="hoursOpen ? 'Outreach hours open' : 'Outside legal hours'"></span>
            </div>
        </header>

        <main class="px-4 py-6 sm:px-8">
            {{ $slot }}
        </main>
    </div>
</body>
</html>