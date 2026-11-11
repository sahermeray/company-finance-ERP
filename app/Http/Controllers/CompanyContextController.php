<?php

namespace App\Http\Controllers;

use App\Http\Requests\SwitchCompanyRequest;
use App\Models\User;
use App\Services\CompanyContextService;
use Illuminate\Http\RedirectResponse;

class CompanyContextController extends Controller
{
    public function update(
        SwitchCompanyRequest $request,
        CompanyContextService $companyContext,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $companyContext->switchTo($user, $request->integer('company_id'));

        return back();
    }
}
