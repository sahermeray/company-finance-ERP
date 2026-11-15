<?php

namespace App\Http\Controllers;

use App\Http\Requests\LocalePreferenceRequest;
use Illuminate\Http\RedirectResponse;

class LocalePreferenceController extends Controller
{
    public function __invoke(LocalePreferenceRequest $request): RedirectResponse
    {
        $request->session()->put('locale', $request->validated('locale'));

        $returnTo = $request->validated('return_to');

        if ($returnTo !== null) {
            return redirect()->to($returnTo);
        }

        return redirect()->route($request->user() === null ? 'login' : 'dashboard.index');
    }
}
