<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\BorrowerRegistrationRequest;
use App\Services\Customer\BorrowerOnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class RegisterController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(
        BorrowerRegistrationRequest $request,
        BorrowerOnboardingService $onboarding
    ): RedirectResponse {
        $borrower = $onboarding->register(
            $request->validated(),
            $request->ip(),
            $request->userAgent()
        );

        $user = $borrower->user;

        if ($user === null) {
            return redirect()->route('login');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()
            ->route('borrower.dashboard')
            ->with('status', 'Your account is ready. Welcome!');
    }
}