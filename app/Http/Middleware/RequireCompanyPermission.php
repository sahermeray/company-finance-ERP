<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\CompanyAuthorizationService;
use App\Services\CompanyContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireCompanyPermission
{
    public function __construct(
        private readonly CompanyContextService $companyContext,
        private readonly CompanyAuthorizationService $authorization,
    ) {}

    /**
     * Handle an incoming request.
     *
     * Usage: ->middleware('company.permission:permission.key')
     * Multiple: ->middleware('company.permission:perm.a,perm.b')  (all required)
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $company = $this->companyContext->current();

        abort_if($company === null, 409, 'An active company context is required.');

        foreach ($permissions as $permission) {
            if (! $this->authorization->check($user, $company, $permission)) {
                abort(403, 'You do not have the required permission: '.$permission);
            }
        }

        return $next($request);
    }
}
