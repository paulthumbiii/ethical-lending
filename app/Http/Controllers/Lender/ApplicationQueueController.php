<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lender;

use App\Enums\StatementReviewStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateApplicationInflowRequest;
use App\Models\LoanApplication;
use App\Models\MakerCheckerRequest;
use App\Models\MpesaStatementUpload;
use App\Services\Governance\MakerCheckerService;
use App\Services\Underwriting\ApplicationReviewService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class ApplicationQueueController extends Controller
{
    public function __construct(private readonly ApplicationReviewService $service)
    {
    }

    public function index(): View
    {
        $waitingCount = LoanApplication::query()
            ->whereIn('status', ApplicationReviewService::CHECKABLE_STATUSES)
            ->count();

        $waiting = LoanApplication::query()
            ->with(['borrower', 'product'])
            ->whereIn('status', ApplicationReviewService::CHECKABLE_STATUSES)
            ->oldest('id')
            ->limit(100)
            ->get();

        $decided = LoanApplication::query()
            ->with(['borrower', 'product'])
            ->whereIn('status', ['approved_pending_checker', 'rejected', 'approved'])
            ->latest('id')
            ->limit(20)
            ->get();

        return view('lender.applications.index', [
            'waitingCount' => $waitingCount,
            'waiting' => $waiting,
            'decided' => $decided,
        ]);
    }

    public function show(LoanApplication $loanApplication): View
    {
        $loanApplication->load([
            'borrower.financialProfile',
            'product',
            'underwriter',
            'ruleEvaluations' => fn ($query) => $query->orderBy('id'),
        ]);

        $statements = MpesaStatementUpload::query()
            ->with('reviewer')
            ->where('loan_application_id', $loanApplication->id)
            ->latest('id')
            ->get();

        $today = now()->toDateString();

        $hasAcceptedStatement = $statements->contains(
            fn (MpesaStatementUpload $s): bool => $s->review_status === StatementReviewStatus::Accepted
                && $s->valid_until !== null
                && $s->valid_until->toDateString() >= $today
        );

        $checkable = in_array($loanApplication->status, ApplicationReviewService::CHECKABLE_STATUSES, true);
        $isUnderwriter = (bool) auth()->user()?->hasAnyRole(UserRole::underwriters());

        $latestApprovalRequest = MakerCheckerRequest::query()
            ->with(['maker', 'checker'])
            ->where('action_type', MakerCheckerService::ACTION_APPROVE_LOAN_APPLICATION)
            ->where('target_entity_type', LoanApplication::class)
            ->where('target_entity_id', $loanApplication->id)
            ->latest('id')
            ->first();

        $canRaiseApproval = $isUnderwriter
            && $loanApplication->status === ApplicationReviewService::STATUS_AWAITING_SECOND_APPROVER
            && ($latestApprovalRequest === null || $latestApprovalRequest->status !== 'pending');

        return view('lender.applications.show', [
            'application' => $loanApplication,
            'statements' => $statements,
            'hasAcceptedStatement' => $hasAcceptedStatement,
            'isUnderwriter' => $isUnderwriter,
            'checkable' => $checkable,
            'canAct' => $checkable && $isUnderwriter,
            'latestApprovalRequest' => $latestApprovalRequest,
            'canRaiseApproval' => $canRaiseApproval,
        ]);
    }

    public function updateInflow(
        UpdateApplicationInflowRequest $request,
        LoanApplication $loanApplication
    ): RedirectResponse {
        $target = redirect()->route('lender.applications.show', ['loanApplication' => $loanApplication->id]);

        try {
            $this->service->updateInflow(
                $loanApplication->id,
                (int) $request->user()->id,
                (string) $request->validated('mpesa_average_monthly_inflow'),
                (string) ($request->ip() ?? '127.0.0.1'),
                (string) ($request->userAgent() ?? 'unknown')
            );
        } catch (RuntimeException $e) {
            return $target->withErrors(['review' => $e->getMessage()]);
        }

        return $target->with('status', 'Verified M-Pesa inflow saved.');
    }

    public function runCheck(Request $request, LoanApplication $loanApplication): RedirectResponse
    {
        $target = redirect()->route('lender.applications.show', ['loanApplication' => $loanApplication->id]);

        try {
            $result = $this->service->runCheck(
                $loanApplication->id,
                (int) $request->user()->id,
                (string) ($request->ip() ?? '127.0.0.1'),
                (string) ($request->userAgent() ?? 'unknown')
            );
        } catch (RuntimeException $e) {
            return $target->withErrors(['review' => $e->getMessage()]);
        }

        $message = $result['status'] === 'rejected'
            ? 'Check complete: the application did not pass.'
            : 'Check complete: passed. It now waits for a second approver.';

        return $target->with('status', $message);
    }

    public function requestApproval(Request $request, LoanApplication $loanApplication): RedirectResponse
    {
        $target = redirect()->route('lender.applications.show', ['loanApplication' => $loanApplication->id]);

        try {
            $this->service->requestFinalApproval(
                $loanApplication->id,
                (int) $request->user()->id,
                (string) ($request->ip() ?? '127.0.0.1'),
                (string) ($request->userAgent() ?? 'unknown')
            );
        } catch (RuntimeException $e) {
            return $target->withErrors(['review' => $e->getMessage()]);
        }

        return $target->with('status', 'Sent for final approval. A second approver will decide it in the Review desk.');
    }
}