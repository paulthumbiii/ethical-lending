<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $login = trim((string) $request->validated('login'));
        $field = str_contains($login, '@') ? 'email' : 'phone_number';

        $signedIn = Auth::attempt([
            $field => $field === 'email' ? strtolower($login) : $this->normalizePhone($login),
            'password' => (string) $request->validated('password'),
            'is_active' => true,
        ], $request->boolean('remember'));

        if (! $signedIn) {
            return $this->fail('Those details do not match an active account. Check them and try again.');
        }

        /** @var User $user */
        $user = Auth::user();

        if ($user->isBorrower()) {
            $request->session()->regenerate();

            return redirect()->route('borrower.dashboard');
        }

        if ($user->isStaff()) {
            $request->session()->regenerate();

            return redirect()->route('lender.dashboard');
        }

        Auth::logout();

        return $this->fail('This account has no access yet. Ask an administrator to assign a role.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function fail(string $message): RedirectResponse
    {
        return back()->withErrors(['login' => $message])->onlyInput('login');
    }

    /** Turns 0700 000 000, +254700000000 or 700000000 into 254700000000. */
    private function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return match (true) {
            str_starts_with($digits, '254') => $digits,
            str_starts_with($digits, '0') => '254' . substr($digits, 1),
            strlen($digits) === 9 => '254' . $digits,
            default => $digits,
        };
    }
}