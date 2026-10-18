@php
    $pretty = fn (string $v): string => ucwords(strtolower(str_replace('_', ' ', $v)));
    $me = (int) auth()->id();
@endphp
<x-layouts.lender title="Review desk">
    <div class="space-y-6"
         x-data="{
            modal: null,
            id: null,
            label: '',
            approveUrl: @js(route('lender.maker-checker.approve', ['makerCheckerRequest' => '__ID__'])),
            rejectUrl: @js(route('lender.maker-checker.reject', ['makerCheckerRequest' => '__ID__'])),
            openModal(kind, id, label) {
                this.modal = kind;
                this.id = id;
                this.label = label;
                this.$nextTick(() => { this.$refs.notes.value = ''; this.$refs.notes.focus(); });
            },
            close() { this.modal = null; },
            get url() {
                return (this.modal === 'approve' ? this.approveUrl : this.rejectUrl).replace('__ID__', this.id);
            }
         }"
         x-on:keydown.escape.window="close()">

        <p class="text-sm text-muted">
            Two-person rule: whoever raises a request can never be the one who decides it.
            Every decision is recorded with your name and time.
        </p>

        @if (session('status'))
            <div role="status" class="rounded-md border border-brand-500 bg-brand-50 px-4 py-3 text-sm text-brand-900">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div role="alert" class="rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ $errors->first() }}
            </div>
        @endif

        <x-card>
            <h2 class="text-sm font-semibold">Waiting for a decision ({{ $pendingCount }})</h2>

            @if ($pending->isEmpty())
                <p class="mt-3 text-sm text-muted">Nothing is waiting. You are all caught up.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[44rem] text-left text-sm">
                        <thead class="text-xs text-muted">
                            <tr>
                                <th class="py-2 pr-4 font-medium">Request</th>
                                <th class="py-2 pr-4 font-medium">Target</th>
                                <th class="py-2 pr-4 font-medium">Details</th>
                                <th class="py-2 pr-4 font-medium">Raised by</th>
                                <th class="py-2 pr-4 font-medium">Raised (EAT)</th>
                                <th class="py-2 font-medium">Decision</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($pending as $r)
                                <tr class="align-top">
                                    <td class="py-3 pr-4">
                                        <p class="font-medium">{{ $pretty($r->action_type) }}</p>
                                        <p class="text-xs tabular-nums text-muted">#{{ $r->id }}</p>
                                    </td>
                                    <td class="py-3 pr-4 tabular-nums">{{ class_basename($r->target_entity_type) }} #{{ $r->target_entity_id }}</td>
                                    <td class="py-3 pr-4">
                                        <dl class="space-y-0.5 text-xs">
                                            @forelse (($r->payload ?? []) as $key => $value)
                                                <div>
                                                    <dt class="inline text-muted">{{ $pretty((string) $key) }}:</dt>
                                                    <dd class="inline">{{ is_scalar($value) ? $value : json_encode($value) }}</dd>
                                                </div>
                                            @empty
                                                <span class="text-muted">No extra details</span>
                                            @endforelse
                                        </dl>
                                    </td>
                                    <td class="py-3 pr-4">{{ $r->maker?->name ?? 'Unknown' }}</td>
                                    <td class="py-3 pr-4 tabular-nums">{{ $r->created_at?->timezone('Africa/Nairobi')->format('d M Y, H:i') }}</td>
                                    <td class="py-3">
                                        @if ((int) $r->maker_user_id === $me)
                                            <p class="text-xs text-muted">Raised by you. A different approver must decide.</p>
                                        @else
                                            <div class="flex gap-2">
                                                <button type="button"
                                                        x-on:click="openModal('approve', {{ $r->id }}, @js($pretty($r->action_type)))"
                                                        class="rounded-md border border-brand-600 px-3 py-1.5 text-xs font-medium text-brand-700 hover:bg-brand-50">Approve</button>
                                                <button type="button"
                                                        x-on:click="openModal('reject', {{ $r->id }}, @js($pretty($r->action_type)))"
                                                        class="rounded-md border border-red-600 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50">Reject</button>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($pendingCount > $pending->count())
                    <p class="mt-3 text-xs text-muted">Showing the oldest {{ $pending->count() }} of {{ $pendingCount }}.</p>
                @endif
            @endif
        </x-card>

        <x-card>
            <h2 class="text-sm font-semibold">Recent decisions</h2>

            @if ($recent->isEmpty())
                <p class="mt-3 text-sm text-muted">No decisions yet.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-left text-sm">
                        <thead class="text-xs text-muted">
                            <tr>
                                <th class="py-2 pr-4 font-medium">Request</th>
                                <th class="py-2 pr-4 font-medium">Outcome</th>
                                <th class="py-2 pr-4 font-medium">Decided by</th>
                                <th class="py-2 pr-4 font-medium">Notes</th>
                                <th class="py-2 font-medium">When (EAT)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($recent as $r)
                                <tr class="align-top">
                                    <td class="py-3 pr-4">
                                        <p class="font-medium">{{ $pretty($r->action_type) }}</p>
                                        <p class="text-xs tabular-nums text-muted">#{{ $r->id }}</p>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <x-badge tone="{{ $r->status === 'approved' ? 'success' : 'danger' }}">{{ $pretty($r->status) }}</x-badge>
                                    </td>
                                    <td class="py-3 pr-4">{{ $r->checker?->name ?? 'Unknown' }}</td>
                                    <td class="py-3 pr-4 text-xs">{{ $r->checker_notes ?: 'No notes' }}</td>
                                    <td class="py-3 tabular-nums">{{ $r->actioned_at?->timezone('Africa/Nairobi')->format('d M Y, H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>

        <div x-show="modal" x-cloak x-transition.opacity
             class="fixed inset-0 z-40 flex items-end justify-center bg-ink/50 p-4 sm:items-center"
             x-on:click.self="close()">
            <div class="w-full max-w-md rounded-lg border border-line bg-white p-5"
                 role="dialog" aria-modal="true" aria-labelledby="mc-modal-title">
                <h2 id="mc-modal-title" class="text-base font-semibold"
                    x-text="modal === 'approve' ? 'Approve this request?' : 'Reject this request?'"></h2>
                <p class="mt-1 text-sm text-muted">
                    <span x-text="label"></span> &middot; request #<span x-text="id"></span>
                </p>

                <form method="POST" x-bind:action="url" class="mt-4 space-y-3">
                    @csrf
                    <label for="mc-notes" class="block text-sm font-medium"
                           x-text="modal === 'reject' ? 'Reason (required)' : 'Notes (optional)'"></label>
                    <textarea id="mc-notes" name="notes" x-ref="notes" rows="3" maxlength="500"
                              x-bind:required="modal === 'reject'"
                              class="w-full rounded-md border border-line px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"></textarea>

                    <div class="flex justify-end gap-2">
                        <button type="button" x-on:click="close()"
                                class="rounded-md border border-line px-4 py-2 text-sm font-medium hover:bg-paper">Cancel</button>
                        <button type="submit"
                                x-bind:class="modal === 'reject' ? 'bg-red-700 hover:bg-red-800' : 'bg-brand-600 hover:bg-brand-700'"
                                class="rounded-md px-4 py-2 text-sm font-medium text-white"
                                x-text="modal === 'reject' ? 'Reject request' : 'Approve request'"></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-layouts.lender>