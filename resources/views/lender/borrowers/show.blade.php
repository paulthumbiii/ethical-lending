@php
    $money = fn ($v): string => 'KES ' . number_format((float) $v, 2);
    $date = fn ($v): string => $v ? \Carbon\CarbonImmutable::parse($v)->format('j M Y') : '-';
    $profile = $borrower->financialProfile;
@endphp
<x-layouts.lender :title="$borrower->full_name">
    <x-page-header :subtitle="'Phone ' . $borrower->phone_number . ' · ID ' . $borrower->id_number">
        <x-slot:actions>
            @if ($borrower->is_blacklisted)
                <x-badge tone="danger">Blacklisted</x-badge>
            @endif
            <a href="{{ route('lender.borrowers.index') }}" class="text-sm font-medium text-muted underline-offset-4 hover:text-ink hover:underline">Back to borrowers</a>
        </x-slot:actions>
    </x-page-header>

    <x-tabs :tabs="$tabs" :active="$tab" />

    @if ($tab === 'overview')
        <div class="grid gap-4 lg:grid-cols-2">
            <x-card title="Identity">
                <dl class="divide-y divide-line text-sm">
                    <div class="flex justify-between py-2"><dt class="text-muted">Full name</dt><dd>{{ $borrower->full_name }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-muted">Phone</dt><dd class="tabular-nums">{{ $borrower->phone_number }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-muted">ID number</dt><dd class="tabular-nums">{{ $borrower->id_number }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-muted">KRA PIN</dt><dd>{{ $borrower->kra_pin ?: '-' }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-muted">Email</dt><dd>{{ $borrower->email ?: '-' }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-muted">Has a login</dt><dd>{{ $borrower->user_id ? 'Yes' : 'No' }}</dd></div>
                </dl>
            </x-card>

            <x-card title="Credit standing">
                <dl class="divide-y divide-line text-sm">
                    <div class="flex justify-between py-2"><dt class="text-muted">Risk band</dt><dd>{{ $borrower->risk_band?->value ?? '-' }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-muted">Credit limit</dt><dd class="tabular-nums">{{ $money($borrower->credit_limit) }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-muted">Blacklisted</dt><dd>{{ $borrower->is_blacklisted ? 'Yes' : 'No' }}</dd></div>
                </dl>
            </x-card>

            <x-card title="Financial profile" class="lg:col-span-2">
                @if ($profile === null)
                    <p class="text-sm text-muted">No financial profile recorded yet.</p>
                @else
                    <dl class="divide-y divide-line text-sm">
                        <div class="flex justify-between py-2"><dt class="text-muted">Monthly income</dt><dd class="tabular-nums">{{ $money($profile->monthly_income) }}</dd></div>
                        <div class="flex justify-between py-2"><dt class="text-muted">Monthly expenses</dt><dd class="tabular-nums">{{ $money($profile->monthly_expenses) }}</dd></div>
                        <div class="flex justify-between py-2"><dt class="text-muted">Existing debt payments</dt><dd class="tabular-nums">{{ $money($profile->existing_debt_obligations) }}</dd></div>
                        <div class="flex justify-between py-2"><dt class="text-muted">Verified average M-Pesa inflow</dt><dd class="tabular-nums">{{ $money($profile->mpesa_average_monthly_inflow) }}</dd></div>
                    </dl>
                @endif
            </x-card>
        </div>
    @elseif ($tab === 'loans')
        <x-data-table :headings="['Loan', 'Product', 'Principal', 'Status', 'Disbursed', 'Due']"
                      :is-empty="$rows->isEmpty()"
                      empty-title="No loans yet"
                      empty-text="This borrower has not been given a loan.">
            @foreach ($rows as $loan)
                <tr>
                    <td class="px-4 py-3 font-medium">{{ $loan->loan_number }}</td>
                    <td class="px-4 py-3">{{ $loan->product?->name ?? '-' }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ $money($loan->principal_disbursed) }}</td>
                    <td class="px-4 py-3"><x-status-badge :status="$loan->status->value" /></td>
                    <td class="px-4 py-3">{{ $date($loan->disbursed_at) }}</td>
                    <td class="px-4 py-3">{{ $date($loan->due_date) }}</td>
                </tr>
            @endforeach
        </x-data-table>
    @elseif ($tab === 'applications')
        <x-data-table :headings="['Application', 'Product', 'Asked for', 'Days', 'Status', 'Opened']"
                      :is-empty="$rows->isEmpty()"
                      empty-title="No applications yet"
                      empty-text="This borrower has not applied for a loan.">
            @foreach ($rows as $application)
                <tr>
                    <td class="px-4 py-3 font-medium">
                        <a href="{{ route('lender.applications.show', $application) }}" class="text-brand-700 underline-offset-4 hover:underline">{{ $application->application_number }}</a>
                    </td>
                    <td class="px-4 py-3">{{ $application->product?->name ?? '-' }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ $money($application->requested_principal) }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ $application->requested_tenure_days }}</td>
                    <td class="px-4 py-3"><x-status-badge :status="$application->status" /></td>
                    <td class="px-4 py-3">{{ $date($application->created_at) }}</td>
                </tr>
            @endforeach
        </x-data-table>
    @elseif ($tab === 'statements')
        <x-data-table :headings="['File', 'Size', 'Review', 'Reviewed', 'Valid until', 'Flags']"
                      :is-empty="$rows->isEmpty()"
                      empty-title="No statements uploaded"
                      empty-text="M-Pesa statements show here once the borrower uploads them.">
            @foreach ($rows as $statement)
                <tr>
                    <td class="px-4 py-3 font-medium">{{ $statement->original_name ?? '-' }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ $statement->file_size ? number_format($statement->file_size / 1024, 0) . ' KB' : '-' }}</td>
                    <td class="px-4 py-3"><x-status-badge :status="$statement->review_status->value" /></td>
                    <td class="px-4 py-3">{{ $date($statement->reviewed_at) }}</td>
                    <td class="px-4 py-3">{{ $date($statement->valid_until) }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ count($statement->flags ?? []) }}</td>
                </tr>
            @endforeach
        </x-data-table>
    @else
        <x-data-table :headings="['When', 'Action', 'From IP']"
                      :is-empty="$rows->isEmpty()"
                      empty-title="No activity recorded"
                      empty-text="Sign-ins and actions on this borrower show here.">
            @foreach ($rows as $row)
                <tr>
                    <td class="px-4 py-3 tabular-nums">{{ $row->created_at?->format('j M Y H:i') ?? '-' }}</td>
                    <td class="px-4 py-3 font-medium">{{ $row->action }}</td>
                    <td class="px-4 py-3 tabular-nums">{{ $row->ip_address }}</td>
                </tr>
            @endforeach
        </x-data-table>
    @endif
</x-layouts.lender>