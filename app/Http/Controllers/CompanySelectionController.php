<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanySelectionController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $companies = $user->companies()
            ->orderBy('name')
            ->orderBy('companies.id')
            ->get(['companies.id', 'companies.name', 'companies.base_currency']);

        return view('companies.select', [
            'companies' => $companies,
            'activeCompanyId' => session('active_company_id'),
        ]);
    }
}
