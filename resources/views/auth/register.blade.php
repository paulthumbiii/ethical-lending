<x-layouts.guest title="Create your account">
    <div class="grid min-h-screen lg:grid-cols-2">
        <section class="hidden flex-col justify-between bg-brand-900 p-12 text-brand-100 lg:flex">
            <p class="text-lg font-semibold text-white">{{ config('app.name') }}</p>
            <div>
                <h2 class="max-w-sm text-4xl font-semibold leading-tight text-white">Lending that shows its working.</h2>
                <p class="mt-4 max-w-sm">Create an account to see your limit, your balances and your legal protections in one place.</p>
            </div>
            <p class="text-sm">Your data is handled under the Kenya Data Protection Act, 2019.</p>
        </section>

        <section class="flex items-start justify-center px-6 py-12 lg:items-center">
            <div class="w-full max-w-sm">
                <h1 class="text-2xl font-semibold">Create your account</h1>
                <p class="mt-1 text-sm text-muted">It takes about two minutes.</p>

                <form method="POST" action="{{ route('register.store') }}" class="mt-8 space-y-8">
                    @csrf

                    <fieldset class="space-y-5">
                        <legend class="text-sm font-semibold">About you</legend>
                        <x-input label="Full name (as on your ID)" name="full_name" value="{{ old('full_name') }}" autocomplete="name" required autofocus />
                        <x-input label="National ID number" name="id_number" value="{{ old('id_number') }}" inputmode="numeric" required />
                        <x-input label="Mobile number (Safaricom or Airtel)" name="phone_number" value="{{ old('phone_number') }}" inputmode="tel" autocomplete="tel" placeholder="0712 345 678" required />
                        <x-input label="Email (optional)" name="email" type="email" value="{{ old('email') }}" autocomplete="email" />
                        <x-input label="KRA PIN (optional)" name="kra_pin" value="{{ old('kra_pin') }}" placeholder="A123456789B" />
                    </fieldset>

                    <fieldset class="space-y-5">
                        <legend class="text-sm font-semibold">Your money each month (KES)</legend>
                        <x-input label="Income" name="monthly_income" value="{{ old('monthly_income') }}" inputmode="decimal" required />
                        <x-input label="Expenses" name="monthly_expenses" value="{{ old('monthly_expenses') }}" inputmode="decimal" required />
                        <x-input label="Average M-Pesa money received (optional)" name="mpesa_average_monthly_inflow" value="{{ old('mpesa_average_monthly_inflow') }}" inputmode="decimal" />
                        <x-input label="Loan repayments you already make (optional)" name="existing_debt_obligations" value="{{ old('existing_debt_obligations') }}" inputmode="decimal" />
                    </fieldset>

                    <fieldset class="space-y-5">
                        <legend class="text-sm font-semibold">Create your password</legend>
                        <x-input label="Password" name="password" type="password" autocomplete="new-password" required />
                        <p class="-mt-3 text-xs text-muted">At least 8 characters, with letters and numbers.</p>
                        <x-input label="Type the password again" name="password_confirmation" type="password" autocomplete="new-password" required />
                    </fieldset>

                    <div>
                        <label class="flex items-start gap-3 text-sm">
                            <input type="checkbox" name="data_protection_consent" value="1"
                                   @checked(old('data_protection_consent'))
                                   class="mt-0.5 rounded border-line accent-brand-700" required>
                            <span>
                                I agree that {{ config('app.name') }} may use my personal details and my M-Pesa transaction data
                                to check whether I can afford a loan, as set out in the Kenya Data Protection Act, 2019.
                                We record the time and device when you tick this box.
                            </span>
                        </label>
                        @error('data_protection_consent')
                            <p class="mt-1.5 text-sm text-rose-700">{{ $message }}</p>
                        @enderror
                    </div>

                    <x-button type="submit" class="w-full">Create account</x-button>

                    <p class="text-sm text-muted">
                        Already have an account?
                        <a href="{{ route('login') }}" class="font-medium text-brand-700 underline underline-offset-4">Sign in</a>
                    </p>
                </form>
            </div>
        </section>
    </div>
</x-layouts.guest>