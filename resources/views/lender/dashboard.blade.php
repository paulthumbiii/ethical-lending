<x-layouts.lender title="Institutional Command Center">
    <div class="space-y-6">

        <!-- STATUS BAR (the page title is already printed by the layout header) -->
        <div class="flex flex-col gap-2 border-b border-line pb-4 md:flex-row md:items-center md:justify-between">
            <p class="text-xs text-muted">CBK Digital Credit Provider Regulatory LMS | Real-Time Financial Ledger</p>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-800">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-600"></span>
                    CBK RegTech Daemon Active
                </span>
                <span class="text-xs text-muted">EAT: {{ now('Africa/Nairobi')->format('H:i') }}</span>
            </div>
        </div>

        <!-- 1. TOP STATUTORY & PORTFOLIO KPI GRID -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <x-card>
                <span class="text-xs font-medium uppercase tracking-wider text-muted">Active Capital</span>
                <div class="mt-2 font-mono text-xl font-bold text-ink">KES {{ number_format((float) $totalDisbursed, 2) }}</div>
                <div class="mt-1 text-2xs text-muted">Performing & Servicing Portfolio</div>
            </x-card>

            <x-card>
                <span class="text-xs font-medium uppercase tracking-wider text-muted">PAR 30 (At Risk)</span>
                <div class="mt-2 font-mono text-xl font-bold {{ bccomp((string) $par30Percentage, '5', 2) > 0 ? 'text-amber-700' : 'text-emerald-700' }}">
                    {{ $par30Percentage }}%
                </div>
                <div class="mt-1 text-2xs text-muted">KES {{ number_format((float) $par30Principal, 2) }} overdue > 30d</div>
            </x-card>

            <x-card>
                <span class="text-xs font-medium uppercase tracking-wider text-muted">IFRS 9 ECL Reserve</span>
                <div class="mt-2 font-mono text-xl font-bold text-indigo-900">KES {{ number_format((float) $totalEclReserve, 2) }}</div>
                <div class="mt-1 text-2xs text-muted">Stage 1, 2 & 3 Loss Provisioning</div>
            </x-card>

            <x-card>
                <span class="text-xs font-medium uppercase tracking-wider text-muted">In-Duplum Freezes</span>
                <div class="mt-2 font-mono text-xl font-bold {{ $inDuplumFrozenCount > 0 ? 'text-rose-700' : 'text-slate-800' }}">
                    {{ $inDuplumFrozenCount }}
                </div>
                <div class="mt-1 text-2xs text-muted">Banking Act Sec 44A Locked</div>
            </x-card>

            <x-card>
                <span class="text-xs font-medium uppercase tracking-wider text-muted">Maker-Checker Queue</span>
                <div class="mt-2 font-mono text-xl font-bold {{ $pendingMakerCheckerCount > 0 ? 'text-amber-600' : 'text-slate-800' }}">
                    {{ $pendingMakerCheckerCount }}
                </div>
                <div class="mt-1 text-2xs text-muted">Awaiting Dual Authorization</div>
            </x-card>
        </div>

        <!-- 2. TWO-COLUMN OPERATIONAL SPLIT -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

            <!-- COLUMN A: DUAL-CONTROL MAKER-CHECKER DESK -->
            <x-card>
                <div class="mb-4 flex items-center justify-between border-b border-line pb-3">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-wider text-ink">Maker-Checker Authorization Desk</h2>
                        <p class="text-xs text-muted">Mandatory dual-control queue before funds or status mutations execute</p>
                    </div>
                    <x-badge :tone="$pendingMakerCheckerCount > 0 ? 'warning' : 'neutral'">
                        {{ $pendingMakerCheckerCount }} Pending
                    </x-badge>
                </div>

                @if($pendingMakerCheckerQueue->isEmpty())
                    <div class="py-8 text-center text-xs text-muted">
                        Zero pending authorization requests. Dual-control queue is clear.
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="border-b border-line bg-paper text-muted">
                                <tr>
                                    <th class="px-2 py-2 font-medium">Action</th>
                                    <th class="px-2 py-2 font-medium">Initiated By</th>
                                    <th class="px-2 py-2 font-medium">Timestamp</th>
                                    <th class="px-2 py-2 text-right font-medium">Control</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach($pendingMakerCheckerQueue as $req)
                                    <tr>
                                        <td class="px-2 py-2.5">
                                            <span class="font-semibold uppercase text-ink">{{ str_replace('_', ' ', $req->action_type) }}</span>
                                            <div class="text-2xs text-muted">{{ class_basename($req->target_entity_type) }} #{{ $req->target_entity_id }}</div>
                                        </td>
                                        <td class="px-2 py-2.5 text-muted">{{ $req->maker->name ?? 'System Officer' }}</td>
                                        <td class="px-2 py-2.5 font-mono text-2xs text-muted">{{ $req->created_at->format('d/m H:i') }}</td>
                                        <td class="px-2 py-2.5 text-right">
                                            <span class="inline-block rounded bg-amber-100 px-2 py-0.5 text-2xs font-semibold text-amber-800">
                                                Requires Checker
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>

            <!-- COLUMN B: STATUTORY IN-DUPLUM (SEC 44A) WATCHLIST -->
            <x-card>
                <div class="mb-4 flex items-center justify-between border-b border-line pb-3">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-wider text-ink">Section 44A In-Duplum Watchlist</h2>
                        <p class="text-xs text-muted">Statutory cap ceiling tracking on defaulted accounts</p>
                    </div>
                    <x-badge :tone="$inDuplumFrozenCount > 0 ? 'danger' : 'neutral'">
                        {{ $inDuplumFrozenCount }} Locked
                    </x-badge>
                </div>

                @if($inDuplumWatchlist->isEmpty())
                    <div class="py-8 text-center text-xs text-muted">
                        Zero loans currently in default tracking. All accounts performing normally.
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="border-b border-line bg-paper text-muted">
                                <tr>
                                    <th class="px-2 py-2 font-medium">Loan</th>
                                    <th class="px-2 py-2 font-medium">Borrower</th>
                                    <th class="px-2 py-2 text-right font-medium">Cap Ceiling</th>
                                    <th class="px-2 py-2 text-right font-medium">Remaining</th>
                                    <th class="px-2 py-2 text-right font-medium">State</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach($inDuplumWatchlist as $item)
                                    @php
                                        $totalAccrued = bcadd((string) $item->total_post_default_interest, (string) $item->total_post_default_penalties, 2);
                                        $headroom = bcsub((string) $item->default_principal_snapshot, $totalAccrued, 2);
                                        $isExhausted = bccomp($headroom, '0.00', 2) <= 0;
                                        $shownHeadroom = $isExhausted ? '0.00' : $headroom;
                                    @endphp
                                    <tr>
                                        <td class="px-2 py-2.5 font-mono font-medium text-ink">#{{ $item->loan->loan_number }}</td>
                                        <td class="px-2 py-2.5 text-muted">{{ $item->loan->borrower->full_name ?? 'N/A' }}</td>
                                        <td class="px-2 py-2.5 text-right font-mono text-muted">KES {{ number_format((float) $item->default_principal_snapshot, 2) }}</td>
                                        <td class="px-2 py-2.5 text-right font-mono {{ $isExhausted ? 'font-bold text-rose-700' : 'text-emerald-700' }}">
                                            KES {{ number_format((float) $shownHeadroom, 2) }}
                                        </td>
                                        <td class="px-2 py-2.5 text-right">
                                            @if($item->is_frozen || $item->loan->is_in_duplum_locked)
                                                <x-badge tone="danger">FROZEN</x-badge>
                                            @else
                                                <x-badge tone="warning">ACCRUING</x-badge>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>

        <!-- 3. LIVE APPEND-ONLY GENERAL LEDGER STREAM -->
        <x-card>
            <div class="mb-4 flex items-center justify-between border-b border-line pb-3">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wider text-ink">Live Append-Only Financial Ledger Feed</h2>
                    <p class="text-xs text-muted">Immutable transactional audit stream across all principal, interest, fee, and repayment events</p>
                </div>
                <span class="font-mono text-2xs text-muted">Immutable Double-Entry Stream</span>
            </div>

            @if($recentLedgerEntries->isEmpty())
                <div class="py-8 text-center text-xs text-muted">
                    No ledger transactions recorded yet.
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-line bg-paper text-muted">
                            <tr>
                                <th class="px-2 py-2 font-medium">Timestamp</th>
                                <th class="px-2 py-2 font-medium">Loan Ref</th>
                                <th class="px-2 py-2 font-medium">Borrower</th>
                                <th class="px-2 py-2 font-medium">Entry Type</th>
                                <th class="px-2 py-2 text-right font-medium">Debit (+)</th>
                                <th class="px-2 py-2 text-right font-medium">Credit (-)</th>
                                <th class="px-2 py-2 text-right font-medium">Running Balance</th>
                                <th class="px-2 py-2 font-medium">Ref / Note</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line font-mono text-2xs">
                            @foreach($recentLedgerEntries as $entry)
                                <tr>
                                    <td class="px-2 py-2.5 text-muted">{{ $entry->created_at->format('d/m H:i:s') }}</td>
                                    <td class="px-2 py-2.5 font-semibold text-ink">#{{ $entry->loan->loan_number ?? $entry->loan_id }}</td>
                                    <td class="px-2 py-2.5 font-sans text-muted">{{ $entry->loan->borrower->full_name ?? 'N/A' }}</td>
                                    <td class="px-2 py-2.5 font-sans">
                                        <span class="rounded px-1.5 py-0.5 text-3xs font-semibold uppercase
                                            {{ in_array($entry->entry_type, ['repayment', 'restructure_waiver']) ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">
                                            {{ str_replace('_', ' ', $entry->entry_type) }}
                                        </span>
                                    </td>
                                    <td class="px-2 py-2.5 text-right {{ bccomp((string) $entry->debit, '0.00', 2) > 0 ? 'font-bold text-rose-700' : 'text-muted' }}">
                                        {{ bccomp((string) $entry->debit, '0.00', 2) > 0 ? 'KES ' . number_format((float) $entry->debit, 2) : '-' }}
                                    </td>
                                    <td class="px-2 py-2.5 text-right {{ bccomp((string) $entry->credit, '0.00', 2) > 0 ? 'font-bold text-emerald-700' : 'text-muted' }}">
                                        {{ bccomp((string) $entry->credit, '0.00', 2) > 0 ? 'KES ' . number_format((float) $entry->credit, 2) : '-' }}
                                    </td>
                                    <td class="px-2 py-2.5 text-right font-bold text-ink">
                                        KES {{ number_format((float) $entry->total_running_balance, 2) }}
                                    </td>
                                    <td class="max-w-xs truncate px-2 py-2.5 font-sans text-muted" title="{{ $entry->notes }}">
                                        {{ $entry->reference ?? $entry->notes ?? '-' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>

    </div>
</x-layouts.lender>