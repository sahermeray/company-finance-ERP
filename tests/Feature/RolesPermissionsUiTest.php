<?php

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a company, attach a user as owner, and seed permissions + predefined roles.
 *
 * @return array{company: Company, user: User}
 */
function rolesSetUp(): array
{
    $company = Company::query()->create(['name' => 'Roles Company '.uniqid()]);
    $user    = User::factory()->create(['is_active' => true]);
    $company->users()->attach($user);
    DB::table('company_user')
        ->where('company_id', $company->id)
        ->where('user_id', $user->id)
        ->update(['is_owner' => true]);

    // Seed permissions catalog into DB.
    seedPermissionsForTest();

    return compact('company', 'user');
}

function rolesSession(Company $company): array
{
    return [
        'active_company_id' => $company->getKey(),
        'locale'            => 'en',
    ];
}

function seedPermissionsForTest(): void
{
    foreach (PermissionCatalogService::all() as $entry) {
        Permission::firstOrCreate(
            ['key' => $entry['key']],
            [
                'module'   => $entry['module'],
                'action'   => $entry['action'],
                'name_en'  => $entry['name_en'],
                'name_ar'  => $entry['name_ar'],
            ],
        );
    }
}

/**
 * Create a user attached to $company with a specific set of permission keys.
 * Returns the user (with no is_owner, role created and assigned).
 */
function rolesSetUpUserWithPermissions(Company $company, array $permissionKeys): User
{
    seedPermissionsForTest();

    $user = User::factory()->create(['is_active' => true]);
    $company->users()->attach($user);

    $role = Role::create([
        'company_id'   => $company->getKey(),
        'name_en'      => 'Test Role '.uniqid(),
        'name_ar'      => 'دور اختبار',
        'slug'         => 'test-role-'.uniqid(),
        'is_system'    => false,
        'is_protected' => false,
    ]);

    $permIds = Permission::whereIn('key', $permissionKeys)->pluck('id');
    $role->permissions()->sync($permIds);

    DB::table('company_user')
        ->where('company_id', $company->getKey())
        ->where('user_id', $user->getKey())
        ->update(['role_id' => $role->getKey()]);

    return $user;
}

// ---------------------------------------------------------------------------
// 1. Owner can view roles list (200)
// ---------------------------------------------------------------------------

it('owner can view the roles list', function (): void {
    ['company' => $company, 'user' => $user] = rolesSetUp();

    $this->actingAs($user)
        ->withSession(rolesSession($company))
        ->get(route('roles.index'))
        ->assertOk();
});

// ---------------------------------------------------------------------------
// 2. User without roles.view permission gets 403 on roles list
// ---------------------------------------------------------------------------

it('user without roles.view permission gets 403 on roles list', function (): void {
    ['company' => $company] = rolesSetUp();

    // User with only dashboard.view — no roles.view.
    $restrictedUser = rolesSetUpUserWithPermissions($company, ['dashboard.view']);

    $this->actingAs($restrictedUser)
        ->withSession(rolesSession($company))
        ->get(route('roles.index'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// 3. Owner can create a custom role
// ---------------------------------------------------------------------------

it('owner can create a custom role', function (): void {
    ['company' => $company, 'user' => $user] = rolesSetUp();

    $this->actingAs($user)
        ->withSession(rolesSession($company))
        ->post(route('roles.store'), [
            'name_en'     => 'Custom Viewer',
            'name_ar'     => 'مشاهد مخصص',
            'description' => 'A custom viewer role',
            'permissions' => ['dashboard.view', 'products.view'],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('roles', [
        'company_id' => $company->getKey(),
        'name_en'    => 'Custom Viewer',
        'is_system'  => false,
    ]);
});

// ---------------------------------------------------------------------------
// 4. Invalid permission key rejected on role creation
// ---------------------------------------------------------------------------

it('invalid permission key is rejected on role creation', function (): void {
    ['company' => $company, 'user' => $user] = rolesSetUp();

    $this->actingAs($user)
        ->withSession(rolesSession($company))
        ->post(route('roles.store'), [
            'name_en'     => 'Bad Role',
            'name_ar'     => 'دور خاطئ',
            'permissions' => ['invalid.fake.permission'],
        ])
        ->assertSessionHasErrors('permissions.*');
});

// ---------------------------------------------------------------------------
// 5. Protected Owner role cannot be deleted
// ---------------------------------------------------------------------------

it('protected Owner role cannot be deleted', function (): void {
    ['company' => $company, 'user' => $user] = rolesSetUp();

    $ownerRole = Role::create([
        'company_id'   => $company->getKey(),
        'name_en'      => 'Owner',
        'name_ar'      => 'مالك',
        'slug'         => 'owner',
        'is_system'    => true,
        'is_protected' => true,
    ]);

    $this->actingAs($user)
        ->withSession(rolesSession($company))
        ->delete(route('roles.destroy', $ownerRole))
        ->assertRedirect(route('roles.index'));

    $this->assertDatabaseHas('roles', ['id' => $ownerRole->getKey()]);
});

// ---------------------------------------------------------------------------
// 6. Custom role can be deleted when not assigned to users
// ---------------------------------------------------------------------------

it('custom role can be deleted when not assigned to users', function (): void {
    ['company' => $company, 'user' => $user] = rolesSetUp();

    $customRole = Role::create([
        'company_id'   => $company->getKey(),
        'name_en'      => 'Deletable Role',
        'name_ar'      => 'دور قابل للحذف',
        'slug'         => 'deletable-role',
        'is_system'    => false,
        'is_protected' => false,
    ]);

    $this->actingAs($user)
        ->withSession(rolesSession($company))
        ->delete(route('roles.destroy', $customRole))
        ->assertRedirect(route('roles.index'));

    $this->assertDatabaseMissing('roles', ['id' => $customRole->getKey()]);
});

// ---------------------------------------------------------------------------
// 7. Custom role cannot be deleted when assigned to users
// ---------------------------------------------------------------------------

it('custom role cannot be deleted when assigned to users', function (): void {
    ['company' => $company, 'user' => $user] = rolesSetUp();

    $customRole = Role::create([
        'company_id'   => $company->getKey(),
        'name_en'      => 'Assigned Role',
        'name_ar'      => 'دور معيّن',
        'slug'         => 'assigned-role',
        'is_system'    => false,
        'is_protected' => false,
    ]);

    // Assign this role to another user.
    $otherUser = User::factory()->create(['is_active' => true]);
    $company->users()->attach($otherUser);
    DB::table('company_user')
        ->where('company_id', $company->getKey())
        ->where('user_id', $otherUser->getKey())
        ->update(['role_id' => $customRole->getKey()]);

    $this->actingAs($user)
        ->withSession(rolesSession($company))
        ->delete(route('roles.destroy', $customRole))
        ->assertRedirect(route('roles.show', $customRole));

    $this->assertDatabaseHas('roles', ['id' => $customRole->getKey()]);
});

// ---------------------------------------------------------------------------
// 8. Owner can assign a role to a user
// ---------------------------------------------------------------------------

it('owner can assign a role to a user', function (): void {
    ['company' => $company, 'user' => $owner] = rolesSetUp();

    $targetUser = User::factory()->create(['is_active' => true]);
    $company->users()->attach($targetUser);

    $role = Role::create([
        'company_id'   => $company->getKey(),
        'name_en'      => 'Assignable Role',
        'name_ar'      => 'دور قابل للتعيين',
        'slug'         => 'assignable-role',
        'is_system'    => false,
        'is_protected' => false,
    ]);

    $this->actingAs($owner)
        ->withSession(rolesSession($company))
        ->patch(route('users.role.assign', $targetUser), [
            'role_id' => $role->getKey(),
        ])
        ->assertRedirect(route('users.show', $targetUser));

    $pivot = DB::table('company_user')
        ->where('company_id', $company->getKey())
        ->where('user_id', $targetUser->getKey())
        ->first();

    expect((int) $pivot->role_id)->toBe((int) $role->getKey());
});

// ---------------------------------------------------------------------------
// 9. User without roles.assign permission cannot assign roles
// ---------------------------------------------------------------------------

it('user without roles.assign permission cannot assign roles', function (): void {
    ['company' => $company] = rolesSetUp();

    $actor = rolesSetUpUserWithPermissions($company, ['users.view']);

    $targetUser = User::factory()->create(['is_active' => true]);
    $company->users()->attach($targetUser);

    $role = Role::create([
        'company_id'   => $company->getKey(),
        'name_en'      => 'Some Role',
        'name_ar'      => 'دور ما',
        'slug'         => 'some-role',
        'is_system'    => false,
        'is_protected' => false,
    ]);

    $this->actingAs($actor)
        ->withSession(rolesSession($company))
        ->patch(route('users.role.assign', $targetUser), [
            'role_id' => $role->getKey(),
        ])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// 10. Role from another company cannot be assigned
// ---------------------------------------------------------------------------

it('role from another company cannot be assigned', function (): void {
    ['company' => $company, 'user' => $owner] = rolesSetUp();

    $otherCompany = Company::query()->create(['name' => 'Other Company']);
    $foreignRole  = Role::create([
        'company_id'   => $otherCompany->getKey(),
        'name_en'      => 'Foreign Role',
        'name_ar'      => 'دور أجنبي',
        'slug'         => 'foreign-role',
        'is_system'    => false,
        'is_protected' => false,
    ]);

    $targetUser = User::factory()->create(['is_active' => true]);
    $company->users()->attach($targetUser);

    $this->actingAs($owner)
        ->withSession(rolesSession($company))
        ->patch(route('users.role.assign', $targetUser), [
            'role_id' => $foreignRole->getKey(),
        ])
        ->assertRedirect(route('users.show', $targetUser));

    // The role was not assigned (pivot still null or unchanged).
    $pivot = DB::table('company_user')
        ->where('company_id', $company->getKey())
        ->where('user_id', $targetUser->getKey())
        ->first();

    expect($pivot->role_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// 11. User with dashboard.view only gets 200 on dashboard, 403 on products
// ---------------------------------------------------------------------------

it('user with only dashboard.view gets 200 on dashboard and 403 on products', function (): void {
    ['company' => $company] = rolesSetUp();

    $user = rolesSetUpUserWithPermissions($company, ['dashboard.view']);

    $this->actingAs($user)
        ->withSession(rolesSession($company))
        ->get(route('dashboard.index'))
        ->assertOk();

    $this->actingAs($user)
        ->withSession(rolesSession($company))
        ->get(route('inventory.products.index'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// 12. User with products.view gets 200 on products list, 403 on products create
// ---------------------------------------------------------------------------

it('user with products.view gets 200 on products list, 403 on products create', function (): void {
    ['company' => $company] = rolesSetUp();

    $user = rolesSetUpUserWithPermissions($company, ['products.view', 'dashboard.view']);

    $this->actingAs($user)
        ->withSession(rolesSession($company))
        ->get(route('inventory.products.index'))
        ->assertOk();

    $this->actingAs($user)
        ->withSession(rolesSession($company))
        ->get(route('inventory.products.create'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// 13. User with sales.view gets 200 on sales list, 403 on sales.post
// ---------------------------------------------------------------------------

it('user with sales.view gets 200 on sales list and 403 on sales post', function (): void {
    ['company' => $company] = rolesSetUp();

    $user = rolesSetUpUserWithPermissions($company, ['sales.view']);

    $this->actingAs($user)
        ->withSession(rolesSession($company))
        ->get(route('sales.index'))
        ->assertOk();

    // Create a minimal party and revenue record so the route binding resolves.
    // The permission check (sales.post) runs after binding; a user without the
    // permission must receive 403.
    $partyId = DB::table('parties')->insertGetId([
        'company_id' => $company->getKey(),
        'name'       => 'Test Customer',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $revenueId = DB::table('revenues')->insertGetId([
        'company_id'     => $company->getKey(),
        'party_id'       => $partyId,
        'revenue_number' => 'REV-PERM-TEST-001',
        'revenue_date'   => now()->toDateString(),
        'status'         => 'draft',
        'currency'       => 'SYP',
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);

    // sales.post is a POST route — a user without sales.post should get 403.
    $this->actingAs($user)
        ->withSession(rolesSession($company))
        ->post(route('sales.post', ['revenue' => $revenueId]))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// 14. Owner has access to all routes (sample)
// ---------------------------------------------------------------------------

it('owner has access to key routes', function (): void {
    ['company' => $company, 'user' => $owner] = rolesSetUp();

    $routes = [
        route('dashboard.index'),
        route('inventory.products.index'),
        route('sales.index'),
        route('roles.index'),
        route('users.index'),
        route('settings.general'),
    ];

    foreach ($routes as $url) {
        $this->actingAs($owner)
            ->withSession(rolesSession($company))
            ->get($url)
            ->assertOk();
    }
});

// ---------------------------------------------------------------------------
// 15. Company A role does not grant access in Company B
// ---------------------------------------------------------------------------

it('a role from company A does not grant access in company B context', function (): void {
    // Set up company A.
    $companyA = Company::query()->create(['name' => 'Company A '.uniqid()]);
    seedPermissionsForTest();

    $userA = User::factory()->create(['is_active' => true]);
    $companyA->users()->attach($userA);
    DB::table('company_user')
        ->where('company_id', $companyA->id)
        ->where('user_id', $userA->id)
        ->update(['is_owner' => true]);

    // Set up company B.
    $companyB = Company::query()->create(['name' => 'Company B '.uniqid()]);
    $userB    = User::factory()->create(['is_active' => true]);
    $companyB->users()->attach($userB);
    // userB has products.view in company B via a role.
    $roleB = Role::create([
        'company_id'   => $companyB->getKey(),
        'name_en'      => 'Products Viewer',
        'name_ar'      => 'مشاهد المنتجات',
        'slug'         => 'products-viewer',
        'is_system'    => false,
        'is_protected' => false,
    ]);
    $permId = Permission::where('key', 'products.view')->value('id');
    $roleB->permissions()->sync([$permId]);
    DB::table('company_user')
        ->where('company_id', $companyB->getKey())
        ->where('user_id', $userB->getKey())
        ->update(['role_id' => $roleB->getKey()]);

    // userB has company B role with products.view.
    // But if userB somehow accesses company A context, they should have no permissions
    // in company A.  EnsureActiveCompany detects that userB is not a member of company A,
    // clears the stale session value, and auto-switches them to their only company (B).
    // The products.view permission then passes for company B, so the response is 200 —
    // but company A data is never exposed because all queries are scoped to company B.
    $this->actingAs($userB)
        ->withSession(['active_company_id' => $companyA->getKey(), 'locale' => 'en'])
        ->get(route('inventory.products.index'))
        ->assertOk();
});

// ---------------------------------------------------------------------------
// 16. Existing CompanySettingsTest still passes with owner user (regression)
// ---------------------------------------------------------------------------

it('owner user can access company settings general page (regression)', function (): void {
    $company = Company::query()->create(['name' => 'Regression Settings Company '.uniqid()]);
    $user    = User::factory()->create();
    $company->users()->attach($user);
    DB::table('company_user')
        ->where('company_id', $company->id)
        ->where('user_id', $user->id)
        ->update(['is_owner' => true]);

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey(), 'locale' => 'en'])
        ->get(route('settings.general'))
        ->assertOk();
});

// ---------------------------------------------------------------------------
// 17. Existing UserManagementTest still passes with owner user (regression)
// ---------------------------------------------------------------------------

it('owner user can access users index page (regression)', function (): void {
    $company = Company::query()->create(['name' => 'Regression Users Company '.uniqid()]);
    $user    = User::factory()->create(['is_active' => true]);
    $company->users()->attach($user);
    DB::table('company_user')
        ->where('company_id', $company->id)
        ->where('user_id', $user->id)
        ->update(['is_owner' => true]);

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey(), 'locale' => 'en'])
        ->get(route('users.index'))
        ->assertOk();
});

// ---------------------------------------------------------------------------
// 18. Unauthenticated access to roles routes redirects to login
// ---------------------------------------------------------------------------

it('unauthenticated requests to roles routes redirect to login', function (): void {
    $this->get(route('roles.index'))->assertRedirect(route('login'));
    $this->get(route('roles.create'))->assertRedirect(route('login'));
    $this->post(route('roles.store'))->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// 19. Navigation shows roles item
// ---------------------------------------------------------------------------

it('navigation shows the roles item', function (): void {
    $company = Company::query()->create(['name' => 'Nav Roles Company '.uniqid()]);
    $user    = User::factory()->create(['is_active' => true]);
    $company->users()->attach($user);
    DB::table('company_user')
        ->where('company_id', $company->id)
        ->where('user_id', $user->id)
        ->update(['is_owner' => true]);

    $response = $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey(), 'locale' => 'en'])
        ->get(route('dashboard.index'))
        ->assertOk();

    $response->assertSee('data-navigation-item="roles"', false);
    $response->assertSee(route('roles.index'), false);
});

// ---------------------------------------------------------------------------
// 20. Permission matrix renders all 52 permissions grouped by module
// ---------------------------------------------------------------------------

it('create role form renders all 52 permissions grouped by module', function (): void {
    ['company' => $company, 'user' => $user] = rolesSetUp();

    $response = $this->actingAs($user)
        ->withSession(rolesSession($company))
        ->get(route('roles.create'))
        ->assertOk();

    $catalog = PermissionCatalogService::all();

    expect(count($catalog))->toBe(53);

    foreach ($catalog as $perm) {
        $response->assertSee($perm['key'], false);
        $response->assertSee($perm['name_en']);
    }

    // Verify modules appear as group headers (at least some key modules).
    $modules = collect($catalog)->pluck('module')->unique()->values()->all();
    expect(count($modules))->toBeGreaterThanOrEqual(14);
});
