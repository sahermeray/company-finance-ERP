<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\CompanyContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(
        LoginRequest $request,
        CompanyContextService $companyContext,
    ): RedirectResponse {
        if (! Auth::attempt($request->safe()->only(['email', 'password']))) {
            return back()
                ->withErrors(['email' => __('ui.auth.invalid_credentials')])
                ->onlyInput('email');
        }

        $request->session()->regenerate();

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        // Reject deactivated accounts after credentials are verified.
        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()
                ->withErrors(['email' => __('ui.users.account_deactivated')])
                ->onlyInput('email');
        }

        if ($companyContext->current() === null && $user->companies()->count() !== 1) {
            return redirect()->route('companies.select');
        }

        return redirect()->intended(route('dashboard.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
