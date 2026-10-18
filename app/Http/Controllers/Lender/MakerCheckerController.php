<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lender;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewMakerCheckerRequest;
use App\Models\MakerCheckerRequest;
use App\Services\Governance\MakerCheckerService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

final class MakerCheckerController extends Controller
{
    public function __construct(private readonly MakerCheckerService $service)
    {
    }

    public function index(): View
    {
        $pendingCount = MakerCheckerRequest::query()->where('status', 'pending')->count();

        $pending = MakerCheckerRequest::query()
            ->with('maker')
            ->where('status', 'pending')
            ->oldest('id')
            ->limit(50)
            ->get();

        $recent = MakerCheckerRequest::query()
            ->with(['maker', 'checker'])
            ->whereIn('status', ['approved', 'rejected'])
            ->latest('actioned_at')
            ->limit(10)
            ->get();

        return view('lender.maker-checker.index', [
            'pendingCount' => $pendingCount,
            'pending'      => $pending,
            'recent'       => $recent,
        ]);
    }

    public function approve(
        ReviewMakerCheckerRequest $request,
        MakerCheckerRequest $makerCheckerRequest
    ): RedirectResponse {
        try {
            $this->service->authorizeRequest(
                $makerCheckerRequest->id,
                (int) $request->user()->id,
                $request->validated('notes')
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['review' => $e->getMessage()]);
        }

        return back()->with('status', "Request #{$makerCheckerRequest->id} approved.");
    }

    public function reject(
        ReviewMakerCheckerRequest $request,
        MakerCheckerRequest $makerCheckerRequest
    ): RedirectResponse {
        try {
            $this->service->rejectRequest(
                $makerCheckerRequest->id,
                (int) $request->user()->id,
                (string) $request->validated('notes')
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['review' => $e->getMessage()]);
        }

        return back()->with('status', "Request #{$makerCheckerRequest->id} rejected.");
    }
}