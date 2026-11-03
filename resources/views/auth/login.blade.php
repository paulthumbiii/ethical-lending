{{-- resources/views/auth/login.blade.php --}}
<x-layouts.guest title="Sign in">
    <div class="grid min-h-screen lg:grid-cols-2">
        <section class="hidden flex-col justify-between bg-brand-900 p-12 text-brand-100 lg:flex">
            <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-accent-400 text-sm font-bold text-brand-900" aria-hidden="true">EL</span>
                <p class="text-lg font-semibold text-white">{{ config('app.name') }}</p>
            </div>
            <div>
                <p class="font-mono text-xs uppercase tracking-widest text-accent-400">Lending, explained</p>
                <h2 class="mt-3 max-w-sm text-4xl font-semibold leading-tight text-white">Lending that shows its working.</h2>
                <p class="mt-4 max-w-sm">Every charge, payment and balance is recorded and explained.</p>
                <ul class="mt-8 space-y-3 text-sm">
                    @foreach (['Consent first', 'Fair collections', 'No hidden fees'] as $promise)
                        <li class="flex items-center gap-3">
                            <span class="flex h-5 w-5 items-center justify-center rounded-full bg-accent-400/20 text-accent-400">
                                <svg class="h-3 w-3" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2.5 6.5l2.2 2.2L9.5 3.8"/></svg>
                            </span>
                            {{ $promise }}
                        </li>
                    @endforeach
                </ul>
            </div>
            <p class="text-sm">Your data is handled under the Kenya Data Protection Act, 2019.</p>
        </section>

        <section class="flex items-center justify-center px-6 py-12">
            <div class="w-full max-w-sm">
                <h1 class="text-2xl font-semibold">Sign in</h1>
                <p class="mt-1 text-sm text-muted">Use your email or phone number.</p>

                <form method="POST" action="{{ route('login.store') }}" class="mt-8 space-y-5">
                    @csrf
                    <x-input label="Email or phone number" name="login" value="{{ old('login') }}" autocomplete="username" required autofocus />
                    <x-input label="Password" name="password" type="password" autocomplete="current-password" required />

                    <label class="flex items-center gap-2 text-sm text-muted" x-data>
                        <input type="checkbox" class="rounded border-line accent-brand-700"
                               x-on:change="document.getElementById('password').type = $event.target.checked ? 'text' : 'password'">
                        Show password
                    </label>

                    <label class="flex items-center gap-2 text-sm text-muted">
                        <input type="checkbox" name="remember" class="rounded border-line accent-brand-700">
                        Keep me signed in on this device
                    </label>

                    <x-button type="submit" class="w-full">Sign in</x-button>

                    <p class="text-sm text-muted">
                        New here?
                        <a href="{{ route('register') }}" class="font-medium text-brand-700 underline underline-offset-4">Create an account</a>
                    </p>
                </form>
            </div>
        </section>
    </div>
</x-layouts.guest>