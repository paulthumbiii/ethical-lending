@props(['application'])

@php
    $steps = app(\App\Services\Customer\ApplicationTimelineService::class)->build($application);
@endphp

<x-card title="Where your application is" class="mt-5">
    <ol class="space-y-4">
        @foreach ($steps as $step)
            @php
                $dot = match ($step['state']) {
                    'done' => 'border-brand-600 bg-brand-600 text-white',
                    'current' => 'border-brand-600 bg-white text-brand-700',
                    'problem' => 'border-rose-600 bg-rose-600 text-white',
                    default => 'border-line bg-white text-muted',
                };
                $mark = match ($step['state']) {
                    'done' => '✓',
                    'current' => '●',
                    'problem' => '!',
                    default => '○',
                };
                $stateText = match ($step['state']) {
                    'done' => 'Done',
                    'current' => 'In progress',
                    'problem' => 'Needs your attention',
                    default => 'Not yet',
                };
            @endphp
            <li class="flex gap-3" data-step="{{ $step['key'] }}" data-state="{{ $step['state'] }}" @if ($step['state'] === 'current') aria-current="step" @endif>
                <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-xs {{ $dot }}" aria-hidden="true">{{ $mark }}</span>
                <div class="text-sm">
                    <p class="{{ $step['state'] === 'upcoming' ? 'text-muted' : 'font-medium' }}">
                        {{ $step['title'] }}
                        <span class="sr-only">({{ $stateText }})</span>
                    </p>
                    @if ($step['detail'])
                        <p class="{{ $step['state'] === 'problem' ? 'text-rose-800' : 'text-muted' }}">{{ $step['detail'] }}</p>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
</x-card>