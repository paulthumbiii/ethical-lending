@props(['loan', 'ceiling', 'phone'])
@php
    $masked = substr($phone, 0, 4) . '•••••' . substr($phone, -3);
@endphp
<div {{ $attributes }}>
    <x-card title="Pay now">
        <form method="POST" action="{{ route('borrower.payment.store', $loan) }}" x-data="{ amount: {{ (int) $ceiling }} }">
            @csrf

            <label for="pay-amount" class="block text-sm font-medium">Amount (KES)</label>
            <input id="pay-amount" name="amount" type="number" inputmode="numeric" min="1" max="{{ (int) $ceiling }}" step="1"
                   x-model.number="amount" required
                   class="mt-1.5 block w-full rounded-md border border-line bg-white px-3.5 py-2.5 text-lg tabular-nums focus:outline-2 focus:outline-brand-600">

            @error('amount')
                <p class="mt-1.5 text-sm text-rose-700">{{ $message }}</p>
            @enderror
            @error('payment')
                <p class="mt-1.5 text-sm text-rose-700" role="alert">{{ $message }}</p>
            @enderror

            <button type="button" class="mt-2 text-sm font-medium text-brand-700 underline-offset-4 hover:underline"
                    x-on:click="amount = {{ (int) $ceiling }}">Pay everything: KES {{ number_format((int) $ceiling) }}</button>

            <x-button type="submit" class="mt-4 w-full">Pay with M-Pesa</x-button>

            <p class="mt-3 text-xs text-muted">A payment prompt will be sent to {{ $masked }}, the number on your account. Enter your M-Pesa PIN on your phone to finish.</p>
        </form>
    </x-card>
</div>