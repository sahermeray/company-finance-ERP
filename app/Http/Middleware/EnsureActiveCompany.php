<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\CompanyContextService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveCompany
{
    public function __construct(private readonly CompanyContextService $companyContext) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        // Block deactivated users from accessing any protected route.
        if (! $user->is_active) {
            Auth::logout();

            if ($request->session()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return redirect()->route('login')
                ->withErrors(['email' => __('ui.users.account_deactivated')]);
        }

        if ($this->companyContext->current() === null) {
            $companies = $user->companies()->orderBy('companies.id')->get();

            abort_if($companies->isEmpty(), 403, 'The user does not belong to a company.');
            abort_if($companies->count() > 1, 409, 'Select an active company before continuing.');

            $this->companyContext->switchTo($user, $companies->first()->getKey());
        }

        return $next($request);
    }
}
