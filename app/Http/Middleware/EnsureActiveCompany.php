<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\CompanyContextService;
use Closure;
use Illuminate\Http\Request;
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

        if ($this->companyContext->current() === null) {
            $companies = $user->companies()->orderBy('companies.id')->get();

            abort_if($companies->isEmpty(), 403, 'The user does not belong to a company.');
            abort_if($companies->count() > 1, 409, 'Select an active company before continuing.');

            $this->companyContext->switchTo($user, $companies->first()->getKey());
        }

        return $next($request);
    }
}
