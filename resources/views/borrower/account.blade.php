@php
    $money = fn (string $v): string => 'KES ' . number_format((float) $v, 2);
    $kycTypes = \App\Models\BorrowerKycDocument::TYPES;
@endphp
<x-layouts.borrower title="Account">
    <h1 class="text-xl font-semibold">My account</h1>

    @if (! $borrower)
        <x-card class="mt-5">
            <p class="text-sm text-muted">Your borrower profile is not linked to this login yet. Contact support to finish setting it up.</p>
        </x-card>
    @else
        <x-card title="Your details" class="mt-5">
            <dl class="divide-y divide-line text-sm">
                <div class="flex justify-between py-2"><dt class="text-muted">Name</dt><dd>{{ $borrower->full_name }}</dd></div>
                <div class="flex justify-between py-2"><dt class="text-muted">Phone</dt><dd class="tabular-nums">{{ $borrower->phone_number }}</dd></div>
                <div class="flex justify-between py-2"><dt class="text-muted">ID number</dt><dd class="tabular-nums">****{{ substr((string) $borrower->id_number, -4) }}</dd></div>
                <div class="flex justify-between py-2"><dt class="text-muted">Credit limit</dt><dd class="tabular-nums">{{ $money((string) $borrower->credit_limit) }}</dd></div>
                <div class="flex justify-between py-2"><dt class="text-muted">Risk band</dt><dd>{{ $borrower->risk_band?->value ?? 'Not set' }}</dd></div>
            </dl>
        </x-card>

        <x-card title="Your documents" class="mt-5">
            @if (session('status'))
                <p class="mb-3 text-sm text-brand-700">{{ session('status') }}</p>
            @endif
            @if ($errors->has('document') || $errors->has('document_type'))
                <p class="mb-3 text-sm text-red-700">{{ $errors->first('document') ?: $errors->first('document_type') }}</p>
            @endif

            <p class="text-xs text-muted">We need a clear photo of the front and back of your national ID to confirm who you are. Files are kept private and only the staff who check them can open them. JPG, PNG or PDF, up to 5 MB.</p>

            <div class="mt-2 divide-y divide-line">
                @foreach ($kycTypes as $type => $label)
                    @php
                        $doc = $documents->get($type);
                        $verified = $doc && $doc->verification_status === \App\Enums\VerificationStatus::VERIFIED;
                        $rejected = $doc && $doc->verification_status === \App\Enums\VerificationStatus::REJECTED;
                        $statusText = match (true) {
                            $doc === null => 'Not uploaded',
                            $verified     => 'Verified',
                            $rejected     => 'Please upload a clearer photo',
                            default       => 'Being checked',
                        };
                    @endphp
                    <div class="py-3">
                        <div class="flex items-start justify-between gap-3">
                            <p class="text-sm font-medium">{{ $label }}</p>
                            <p class="text-xs {{ $rejected ? 'text-red-700' : 'text-muted' }}">{{ $statusText }}</p>
                        </div>

                        @if (! $verified)
                            <form method="POST" action="{{ route('borrower.documents.store') }}" enctype="multipart/form-data" class="mt-2 flex flex-col gap-2">
                                @csrf
                                <input type="hidden" name="document_type" value="{{ $type }}">
                                <input type="file" name="document" accept=".jpg,.jpeg,.png,.pdf" class="block w-full text-xs text-muted file:mr-3 file:rounded-md file:border file:border-line file:bg-white file:px-3 file:py-1.5 file:text-xs file:font-medium">
                                <button type="submit" class="self-start rounded-md bg-brand-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-700">{{ $doc ? 'Replace' : 'Upload' }}</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-card>

        <h2 class="mt-6 text-sm font-semibold">Your loans</h2>

        @forelse ($loans as $row)
            @php
                $loan = $row['loan'];
                $hasContract = ! in_array($loan->status, [\App\Enums\LoanStatus::PENDING, \App\Enums\LoanStatus::APPROVAL_HOLD], true);
            @endphp
            <x-card class="mt-3">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-medium">{{ $loan->loan_number }}</p>
                        <p class="text-xs text-muted">Borrowed {{ $money((string) $loan->principal_disbursed) }}</p>
                    </div>
                    <x-status-badge :status="$loan->status->value" />
                </div>
                <dl class="mt-3 divide-y divide-line text-sm">
                    <div class="flex justify-between py-2">
                        <dt class="text-muted">Sent on</dt>
                        <dd>{{ $loan->disbursed_at?->timezone('Africa/Nairobi')->format('j M Y') ?? 'Not yet' }}</dd>
                    </div>
                    <div class="flex justify-between py-2">
                        <dt class="text-muted">Due date</dt>
                        <dd>{{ $loan->due_date?->timezone('Africa/Nairobi')->format('j M Y') ?? 'Not set' }}</dd>
                    </div>
                    <div class="flex justify-between py-2">
                        <dt class="text-muted">Still owed</dt>
                        <dd class="tabular-nums">{{ $money($row['owed']) }}</dd>
                    </div>
                </dl>

                @if (count($row['schedule']) > 0)
                    <h3 class="mt-4 text-sm font-semibold">Your repayment schedule</h3>
                    <ul class="mt-2 divide-y divide-line text-sm">
                        @foreach ($row['schedule'] as $item)
                            <li class="flex items-center justify-between gap-3 py-2">
                                <div>
                                    <p class="font-medium">Payment {{ $item['number'] }}</p>
                                    <p class="text-xs text-muted">Due {{ $item['due']->format('j M Y') }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="tabular-nums">{{ $money($item['left']) }}</span>
                                    <x-status-badge :status="$item['status']" />
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs text-muted">Each amount is what is still to pay on that instalment.</p>
                @endif

                @if ($hasContract)
                    <p class="mt-3 text-sm">
                        <a href="{{ route('borrower.contract', ['loan' => $loan->id]) }}" target="_blank" rel="noopener" class="font-medium text-brand-700 underline-offset-4 hover:underline">Download contract</a>
                        <span class="text-xs text-muted">(use Print, then Save as PDF)</span>
                    </p>
                @endif
            </x-card>
        @empty
            <x-card class="mt-3">
                <p class="text-sm text-muted">You have no loans yet.</p>
            </x-card>
        @endforelse
    @endif
</x-layouts.borrower>