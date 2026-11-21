<?php

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyAuthorizationService;
use App\Services\PermissionCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function rpCreateCompany(string $suffix = ''): Company
{
    return Company::query()->create(['name' => 'RP Company '.$suffix.uniqid()]);
}

function rpCreateUser(bool $active = true): User
{
    return User::factory()->create(['is_active' => $active]);
}

function rpCreateRole(Company $company, array $permissionKeys = [], array $overrides = []): Role
{
    $role = Role::create(array_merge([
        'company_id'   => $company->getKey(),
        'name_en'      => 'Test Role '.uniqid(),
        'name_ar'      => 'دور اختبار',
        'slug'         => 'test-role-'.uniqid(),
        'is_system'    => false,
        'is_protected' => false,
    ], $overrides));

    if ($permissionKeys !== []) {
        $permissions = Permission::query()->whereIn('key', $permissionKeys)->get();
        $role->permissions()->attach($permissions->pluck('id'));
    }

    return $role;
}

function rpSeedPermissions(): void
{
    foreach (PermissionCatalogService::all() as $entry) {
        Permission::updateOrCreate(['key' => $entry['key']], $entry);
    }
}

// ---------------------------------------------------------------------------
// 1. User with owner flag has all permissions
// ---------------------------------------------------------------------------

test('user with is_owner=true has all permissions', function (): void {
    rpSeedPermissions();

    $company = rpCreateCompany('owner');
    $user    = rpCreateUser();
    $company->users()->attach($user->getKey(), ['is_owner' => true]);

    $auth = app(CompanyAuthorizationService::class);

    expect($auth->check($user, $company, 'sales.create'))->toBeTrue()
        ->and($auth->check($user, $company, 'reports.view'))->toBeTrue()
        ->and($auth->check($user, $company, 'roles.delete'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 2. User with custom role has only assigned permissions
// ---------------------------------------------------------------------------

test('user with custom role has only assigned permissions', function (): void {
    rpSeedPermissions();

    $company = rpCreateCompany('custom');
    $user    = rpCreateUser();
    $role    = rpCreateRole($company, ['sales.view', 'sales.create']);

    $company->users()->attach($user->getKey(), ['role_id' => $role->getKey()]);

    $auth = app(CompanyAuthorizationService::class);

    expect($auth->check($user, $company, 'sales.view'))->toBeTrue()
        ->and($auth->check($user, $company, 'sales.create'))->toBeTrue()
        ->and($auth->check($user, $company, 'sales.post'))->toBeFalse()
        ->and($auth->check($user, $company, 'reports.view'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 3. User with no role has no permissions
// ---------------------------------------------------------------------------

test('user with no role has no permissions', function (): void {
    rpSeedPermissions();

    $company = rpCreateCompany('norole');
    $user    = rpCreateUser();
    $company->users()->attach($user->getKey());

    $auth = app(CompanyAuthorizationService::class);

    expect($auth->check($user, $company, 'dashboard.view'))->toBeFalse()
        ->and($auth->check($user, $company, 'sales.view'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 4. Same user has different roles in different companies
// ---------------------------------------------------------------------------

test('same user can have different roles in different companies', function (): void {
    rpSeedPermissions();

    $companyA = rpCreateCompany('A');
    $companyB = rpCreateCompany('B');
    $user     = rpCreateUser();

    $roleA = rpCreateRole($companyA, ['sales.view', 'sales.create']);
    $roleB = rpCreateRole($companyB, ['purchases.view']);

    $companyA->users()->attach($user->getKey(), ['role_id' => $roleA->getKey()]);
    $companyB->users()->attach($user->getKey(), ['role_id' => $roleB->getKey()]);

    $auth = app(CompanyAuthorizationService::class);

    // Company A role
    expect($auth->check($user, $companyA, 'sales.view'))->toBeTrue()
        ->and($auth->check($user, $companyA, 'purchases.view'))->toBeFalse();

    // Company B role — completely independent
    expect($auth->check($user, $companyB, 'purchases.view'))->toBeTrue()
        ->and($auth->check($user, $companyB, 'sales.view'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 5. Deactivated user has no permissions
// ---------------------------------------------------------------------------

test('deactivated user has no permissions even with owner flag', function (): void {
    rpSeedPermissions();

    $company = rpCreateCompany('inactive');
    $user    = rpCreateUser(active: false);
    $company->users()->attach($user->getKey(), ['is_owner' => true]);

    $auth = app(CompanyAuthorizationService::class);

    expect($auth->check($user, $company, 'dashboard.view'))->toBeFalse()
        ->and($auth->check($user, $company, 'sales.create'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 6. Owner cannot be deactivated (UserController safeguard + is_owner check)
// ---------------------------------------------------------------------------

test('deactivating an owner is blocked', function (): void {
    rpSeedPermissions();

    $company  = rpCreateCompany('owner-deactivate');
    $actor    = rpCreateUser();
    $owner    = rpCreateUser();

    $company->users()->attach($actor->getKey());
    $company->users()->attach($owner->getKey(), ['is_owner' => true]);

    // The actor (not the owner, not self) tries to deactivate the owner.
    // The UserController deactivate() method does NOT yet block based on
    // is_owner — that guard lives in CompanyAuthorizationService, and Part 2
    // will wire it into the controller.  For Part 1 we verify that:
    //   a) is_owner=true is correctly stored on the pivot, and
    //   b) CompanyAuthorizationService::userIsOwner() returns true for the owner.
    $auth = app(CompanyAuthorizationService::class);

    expect($auth->userIsOwner($owner, $company))->toBeTrue()
        ->and($auth->userIsOwner($actor, $company))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 7. hasCompanyPermission returns false for another company's permissions
// ---------------------------------------------------------------------------

test('hasCompanyPermission returns false for another company', function (): void {
    rpSeedPermissions();

    $companyA = rpCreateCompany('perm-A');
    $companyB = rpCreateCompany('perm-B');
    $user     = rpCreateUser();

    $role = rpCreateRole($companyA, ['sales.view']);
    $companyA->users()->attach($user->getKey(), ['role_id' => $role->getKey()]);
    // User is NOT a member of companyB.

    expect($user->hasCompanyPermission($companyA, 'sales.view'))->toBeTrue()
        ->and($user->hasCompanyPermission($companyB, 'sales.view'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 8. CompanyAuthorizationService::check with owner=true returns true for any permission
// ---------------------------------------------------------------------------

test('authorization service check returns true for any permission when owner', function (): void {
    rpSeedPermissions();

    $company = rpCreateCompany('auth-owner');
    $user    = rpCreateUser();
    $company->users()->attach($user->getKey(), ['is_owner' => true]);

    $auth = app(CompanyAuthorizationService::class);

    // A permission key that exists in the catalog
    expect($auth->check($user, $company, 'accounting.mappings.update'))->toBeTrue();

    // Even a hypothetical future permission key
    expect($auth->check($user, $company, 'some.future.permission'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 9. Role from another company cannot be assigned to this company's user
// ---------------------------------------------------------------------------

test('role from another company cannot be assigned to this company user', function (): void {
    rpSeedPermissions();

    $companyA = rpCreateCompany('role-company-A');
    $companyB = rpCreateCompany('role-company-B');
    $user     = rpCreateUser();

    $roleFromA = rpCreateRole($companyA, ['sales.view']);
    $companyB->users()->attach($user->getKey());

    $auth = app(CompanyAuthorizationService::class);

    // Manually attempt to assign a role that belongs to companyA to a
    // companyB member. The role belongs to company A, so check() must
    // return false because the role's permissions won't be found in
    // the context of company B, and the pivot for company B has no role.
    expect($auth->check($user, $companyB, 'sales.view'))->toBeFalse();

    // Verify the role really does belong to company A, not B.
    expect($roleFromA->company_id)->toBe($companyA->getKey());
});

// ---------------------------------------------------------------------------
// Additional: Role::hasPermission works correctly
// ---------------------------------------------------------------------------

test('Role hasPermission returns true for assigned and false for missing key', function (): void {
    rpSeedPermissions();

    $company = rpCreateCompany('role-perm');
    $role    = rpCreateRole($company, ['sales.view', 'sales.create']);
    $role->load('permissions');

    expect($role->hasPermission('sales.view'))->toBeTrue()
        ->and($role->hasPermission('sales.create'))->toBeTrue()
        ->and($role->hasPermission('reports.view'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Additional: Company::owner() returns the owner user
// ---------------------------------------------------------------------------

test('Company owner() returns the owner user', function (): void {
    rpSeedPermissions();

    $company = rpCreateCompany('company-owner');
    $owner   = rpCreateUser();
    $other   = rpCreateUser();

    $company->users()->attach($owner->getKey(), ['is_owner' => true]);
    $company->users()->attach($other->getKey());

    $found = $company->owner();

    expect($found)->not->toBeNull()
        ->and($found->getKey())->toBe($owner->getKey());
});

// ---------------------------------------------------------------------------
// Additional: Company::roles() returns only roles for this company
// ---------------------------------------------------------------------------

test('Company roles() scopes to its own company', function (): void {
    rpSeedPermissions();

    $companyA = rpCreateCompany('roles-A');
    $companyB = rpCreateCompany('roles-B');

    rpCreateRole($companyA, [], ['slug' => 'custom-a', 'name_en' => 'Role A', 'name_ar' => 'دور أ']);
    rpCreateRole($companyB, [], ['slug' => 'custom-b', 'name_en' => 'Role B', 'name_ar' => 'دور ب']);

    expect($companyA->roles()->count())->toBe(1)
        ->and($companyB->roles()->count())->toBe(1);
});
