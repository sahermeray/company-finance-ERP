<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CompanyContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(
        private readonly CompanyContextService $companyContext,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search'    => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'string', 'in:1,0'],
        ]);

        $query = User::query()
            ->when(
                $filters['search'] ?? null,
                fn ($q, $search) => $q->where(
                    fn ($q) => $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"),
                ),
            )
            ->when(
                isset($filters['is_active']) && $filters['is_active'] !== '',
                fn ($q) => $q->where('is_active', (bool) $filters['is_active']),
            )
            ->withCount('companies')
            ->orderBy('name');

        $users = $query->paginate(25)->withQueryString();

        return view('users.index', [
            'users'   => $users,
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        $company = $this->companyContext->requireCurrent();

        return view('users.create', [
            'company' => $company,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $company = $this->companyContext->requireCurrent();

        $data = $request->validate([
            'name'                  => ['required', 'string', 'max:255'],
            'email'                 => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password'              => ['required', 'confirmed', Password::min(8)],
            'add_to_company'        => ['nullable', 'boolean'],
        ]);

        $user = User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => Hash::make($data['password']),
            'is_active' => true,
        ]);

        if (! empty($data['add_to_company'])) {
            $user->companies()->attach($company->getKey());
        }

        return redirect()->route('users.show', $user)
            ->with('status', __('ui.users.created'));
    }

    public function show(User $user): View
    {
        $company = $this->companyContext->requireCurrent();

        $memberships = $user->companies()->withPivot('created_at')->orderBy('name')->get();
        $isMember    = $memberships->contains(fn ($c) => $c->getKey() === $company->getKey());

        return view('users.show', [
            'user'       => $user,
            'memberships'=> $memberships,
            'company'    => $company,
            'isMember'   => $isMember,
        ]);
    }

    public function edit(User $user): View
    {
        return view('users.edit', [
            'user' => $user,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'email'        => ['required', 'string', 'email', 'max:255', 'unique:users,email,'.$user->getKey()],
            'new_password' => ['nullable', 'string', 'confirmed', Password::min(8)],
        ]);

        $user->name  = $data['name'];
        $user->email = $data['email'];

        if (! empty($data['new_password'])) {
            $user->password = Hash::make($data['new_password']);
        }

        $user->save();

        return redirect()->route('users.show', $user)
            ->with('status', __('ui.users.updated'));
    }

    public function activate(User $user): RedirectResponse
    {
        $user->is_active = true;
        $user->save();

        return redirect()->route('users.show', $user)
            ->with('status', __('ui.users.activated'));
    }

    public function deactivate(User $user): RedirectResponse
    {
        // Cannot deactivate yourself.
        if ((int) Auth::id() === (int) $user->getKey()) {
            return redirect()->route('users.show', $user)
                ->withErrors(['deactivate' => __('ui.users.cannot_deactivate_self')]);
        }

        // Cannot deactivate the last active user globally.
        $activeCount = User::query()->where('is_active', true)->count();
        if ($activeCount <= 1) {
            return redirect()->route('users.show', $user)
                ->withErrors(['deactivate' => __('ui.users.cannot_deactivate_last')]);
        }

        $user->is_active = false;
        $user->save();

        return redirect()->route('users.show', $user)
            ->with('status', __('ui.users.deactivated'));
    }

    public function addMembership(Request $request, User $user): RedirectResponse
    {
        $company = $this->companyContext->requireCurrent();

        $alreadyMember = $user->companies()->whereKey($company->getKey())->exists();

        if ($alreadyMember) {
            return redirect()->route('users.show', $user)
                ->withErrors(['membership' => __('ui.users.already_member')]);
        }

        $user->companies()->attach($company->getKey());

        return redirect()->route('users.show', $user)
            ->with('status', __('ui.users.membership_added'));
    }

    public function removeMembership(User $user, int $company): RedirectResponse
    {
        $activeCompany = $this->companyContext->requireCurrent();

        // Only allow removing membership from the active company.
        if ((int) $company !== (int) $activeCompany->getKey()) {
            abort(403);
        }

        // Prevent removing your own membership if you are the only member.
        if ((int) Auth::id() === (int) $user->getKey()) {
            $memberCount = $activeCompany->users()->count();
            if ($memberCount <= 1) {
                return redirect()->route('users.show', $user)
                    ->withErrors(['membership' => __('ui.users.cannot_remove_last_member')]);
            }
        }

        $user->companies()->detach($company);

        return redirect()->route('users.show', $user)
            ->with('status', __('ui.users.membership_removed'));
    }
}
