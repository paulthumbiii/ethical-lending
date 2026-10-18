<x-layouts.guest title="Sign in">
    <div class="grid min-h-screen lg:grid-cols-2">
        <section class="hidden flex-col justify-between bg-brand-900 p-12 text-brand-100 lg:flex">
            <p class="text-lg font-semibold text-white">{{ config('app.name') }}</p>
            <div>
                <h2 class="max-w-sm text-4xl font-semibold leading-tight text-white">Lending that shows its working.</h2>
                <p class="mt-4 max-w-sm">Every charge, payment and balance is recorded and explained.</p>
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