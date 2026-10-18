<div>
    <!-- The biggest battle is the war against ignorance. - Mustafa Kemal Atatürk -->
</div>@props(['meter'])
@php
    $state = $meter['state'];
    $money = fn (string $v): string => 'KES ' . number_format((float) $v, 2);
@endphp
<div {{ $attributes }}>
    <x-card title="Your legal limit on charges">
        @if ($state === 'not_triggered')
            <p class="text-sm text-muted">This limit only starts if a loan goes into default. Your loan has not reached that point, so nothing is being counted against it.</p>
        @else
            <div class="flex items-center justify-between gap-3">
                <p class="text-sm text-muted">Interest and penalties added since default</p>
                <x-badge :tone="$state === 'frozen' ? 'success' : 'warning'">{{ $state === 'frozen' ? 'Charges stopped' : 'Counting' }}</x-badge>
            </div>

            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $money($meter['accrued']) }}</p>

            <div class="mt-3 h-3 overflow-hidden rounded-full bg-line"
                 role="progressbar" aria-label="Share of the legal limit used"
                 aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) $meter['percent'] }}">
                <div class="h-full rounded-full {{ $state === 'frozen' ? 'bg-brand-600' : 'bg-amber-500' }}"
                     style="width: {{ $meter['percent'] }}%"></div>
            </div>
            <div class="mt-2 flex justify-between text-xs tabular-nums text-muted">
                <span>KES 0.00</span>
                <span>Limit {{ $money($meter['cap']) }}</span>
            </div>

            @if ($state === 'frozen')
                <p class="mt-4 text-sm">You have reached the limit. No more interest or penalties can be added to this loan.</p>
            @else
                <p class="mt-4 text-sm">The law says charges added after default can never go above the amount you owed at default. At most {{ $money($meter['headroom']) }} more can be added.</p>
            @endif

            <p class="mt-3 text-xs text-muted">Banking Act, section 44A</p>
        @endif
    </x-card>
</div>