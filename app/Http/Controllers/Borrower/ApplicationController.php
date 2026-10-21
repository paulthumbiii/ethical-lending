<?php

declare(strict_types=1);

namespace App\Http\Controllers\Borrower;

use App\Enums\StatementReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLoanApplicationRequest;
use App\Models\AuditTrail;
use App\Models\Borrower;
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\MpesaStatementUpload;
use App\Services\Customer\BorrowerPortalService;
use App\Services\Underwriting\ApplicationReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ApplicationController extends Controller
{
    /** Statuses that mean "still being processed". */
    public const OPEN_STATUSES = ['submitted', 'pending', 'approved_pending_checker', 'approved'];

    public function __construct(private readonly BorrowerPortalService $portal) {}

    public function create(): \Illuminate\Contracts\View\View|RedirectResponse
    {
        $borrower = auth()->user()->borrower;

        if (! $borrower instanceof Borrower) {
            return redirect()->route('borrower.dashboard');
        }

        $latest = LoanApplication::query()
            ->where('borrower_id', $borrower->id)
            ->latest('id')
            ->first();

        $openApplication = $latest !== null && in_array($latest->status, self::OPEN_STATUSES, true) ? $latest : null;

        $statement = $openApplication !== null
            ? MpesaStatementUpload::query()
                ->where('loan_application_id', $openApplication->id)
                ->latest('id')
                ->first()
            : null;

        $canUploadStatement = $openApplication !== null
            && in_array($openApplication->status, ApplicationReviewService::CHECKABLE_STATUSES, true)
            && ($statement === null || $statement->review_status !== StatementReviewStatus::Accepted);

        return view('borrower.apply', [
            'borrower' => $borrower,
            'products' => LoanProduct::query()
                ->where('is_active', true)
                ->with(['interestRules', 'feeMatrices'])
                ->orderBy('min_principal')
                ->get(),
            'openApplication' => $openApplication,
            'statement' => $statement,
            'canUploadStatement' => $canUploadStatement,
            'lastRejected' => $latest !== null && $latest->status === 'rejected' ? $latest : null,
            'hasActiveLoan' => $this->portal->currentLoan($borrower) !== null,
        ]);
    }

    public function store(StoreLoanApplicationRequest $request): RedirectResponse
    {
        $borrower = auth()->user()->borrower;

        if (! $borrower instanceof Borrower) {
            return redirect()->route('borrower.dashboard');
        }

        $product = LoanProduct::query()
            ->where('id', $request->integer('loan_product_id'))
            ->where('is_active', true)
            ->first();

        if ($product === null) {
            throw ValidationException::withMessages(['loan_product_id' => 'That loan product is not available.']);
        }

        $amount = bcadd((string) $request->validated('amount'), '0', 2);

        if (bccomp($amount, (string) $product->min_principal, 2) < 0 || bccomp($amount, (string) $product->max_principal, 2) > 0) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'For %s, choose between KES %s and KES %s.',
                    $product->name,
                    number_format((float) $product->min_principal, 0),
                    number_format((float) $product->max_principal, 0)
                ),
            ]);
        }

        DB::transaction(function () use ($borrower, $product, $amount, $request): void {
            // Lock the borrower row so a double tap cannot create two applications.
            Borrower::query()->where('id', $borrower->id)->lockForUpdate()->firstOrFail();

            $open = LoanApplication::query()
                ->where('borrower_id', $borrower->id)
                ->whereIn('status', self::OPEN_STATUSES)
                ->exists();

            if ($open) {
                throw ValidationException::withMessages(['amount' => 'You already have an application in progress.']);
            }

            if ($this->portal->currentLoan($borrower) !== null) {
                throw ValidationException::withMessages(['amount' => 'Please finish repaying your current loan before applying again.']);
            }

            $application = LoanApplication::create([
                'application_number' => 'APP-' . now()->format('ymd') . '-' . strtoupper(Str::random(6)),
                'borrower_id' => $borrower->id,
                'loan_product_id' => $product->id,
                'requested_principal' => $amount,
                'requested_tenure_days' => (int) $product->default_tenure_days,
                'status' => 'pending',
            ]);

            AuditTrail::create([
                'user_id' => auth()->id(),
                'action' => 'LOAN_APPLICATION_SUBMITTED',
                'auditable_type' => LoanApplication::class,
                'auditable_id' => $application->id,
                'old_values' => null,
                'new_values' => [
                    'product' => $product->product_code,
                    'amount' => $amount,
                    'tenure_days' => (int) $product->default_tenure_days,
                ],
                'ip_address' => substr((string) ($request->ip() ?? '127.0.0.1'), 0, 45),
                'user_agent' => substr((string) ($request->userAgent() ?? 'unknown'), 0, 255),
            ]);
        });

        return redirect()->route('borrower.apply.create')
            ->with('status', 'Application received. We will review it and update you here.');
    }
}