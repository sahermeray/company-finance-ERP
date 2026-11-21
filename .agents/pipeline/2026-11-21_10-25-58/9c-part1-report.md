# Phase 9C Part 1 — Core Infrastructure: Progress Report

## Status: COMPLETE

**Test results**: 613 tests, 2544 assertions, 0 failures (baseline was 601/2514/0)
**New tests added**: 12 (30 assertions) — all passing

---

## Files Created

### Database Migrations (not yet run)

| File | Purpose |
|------|---------|
| `2026_11_22_000001_create_roles_table.php` | Migration B — creates `roles` with company_id FK, slug unique within company |
| `2026_11_22_000002_add_role_id_to_company_user_table.php` | Migration A — adds `role_id` (nullable FK → roles, SET NULL) and `is_owner` (boolean) to pivot |
| `2026_11_22_000003_create_permissions_table.php` | Migration C — creates `permissions` with stable `key` (UNIQUE) |
| `2026_11_22_000004_create_role_permissions_table.php` | Migration D — pivot table with UNIQUE(role_id, permission_id) |

### Models

| File | Changes |
|------|---------|
| `app/Models/Role.php` | New — fillable, BelongsTo Company, BelongsToMany Permission, `hasPermission(string): bool` |
| `app/Models/Permission.php` | New — fillable, BelongsToMany Role, static `catalog(): Collection` |
| `app/Models/User.php` | Added `roleInCompany(Company): ?Role` and `hasCompanyPermission(Company, string): bool` |
| `app/Models/Company.php` | Added `roles(): HasMany` and `owner(): ?User` |

### Services

| File | Purpose |
|------|---------|
| `app/Services/PermissionCatalogService.php` | Static `all()` returning 52 permission entries derived from actual routes |
| `app/Services/CompanyAuthorizationService.php` | `check()`, `requirePermission()`, `userIsOwner()` |

### Middleware

| File | Purpose |
|------|---------|
| `app/Http/Middleware/RequireCompanyPermission.php` | `company.permission:key` middleware, registered as alias in bootstrap/app.php |

### Seeders (not yet run)

| File | Purpose |
|------|---------|
| `database/seeders/RolesAndPermissionsSeeder.php` | Idempotent seeder for permissions + 7 predefined roles per company |

### Tests

| File | Tests |
|------|-------|
| `tests/Feature/RolesPermissionsTest.php` | 12 tests covering all 9 specified scenarios plus 3 additional |

### Providers & Config

- `app/Providers/AppServiceProvider.php` — registered `CompanyAuthorizationService` singleton; extended the view composer to share `$currentUserPermissions` (array of keys) and `$currentUserIsOwner` (bool) with all layout views
- `bootstrap/app.php` — registered `company.permission` middleware alias

---

## Permission Catalog Summary

52 permissions across 14 modules:

| Module | Permissions |
|--------|-------------|
| dashboard | view |
| products | view, create, update |
| inventory | view, adjust, count |
| catalog | view, manage |
| warehouses | view, create, update |
| customers | view, create, update |
| suppliers | view, create, update |
| sales | view, create, post |
| purchases | view, create, post |
| payments | view, create, reverse |
| expenses | view, create, approve, post, settle, reverse, delete |
| cash_accounts | view, create, update |
| accounting | journals.view, mappings.view, mappings.update |
| reports | view |
| company_settings | view, update |
| users | view, create, update, deactivate, manage_memberships |
| roles | view, create, update, delete, assign |

---

## Predefined Roles (seeder, not yet run)

| Slug | is_system | is_protected | Permission set |
|------|-----------|--------------|----------------|
| owner | true | true | All permissions |
| administrator | true | false | All except none — broad admin |
| accountant | true | false | Accounting, payments, expenses, reports |
| sales | true | false | Sales, customers, payments.view |
| purchases | true | false | Purchases, suppliers, payments.view |
| warehouse | true | false | Products, inventory, warehouses, catalog |
| viewer | true | false | All *.view permissions only |

---

## Notes

1. **Migrations not run**: All 4 migration files are created but `php artisan migrate` has not been executed. This is intentional per the instructions.

2. **Seeder not run**: `RolesAndPermissionsSeeder` is created but not invoked. Each company must call it explicitly to initialise its role system.

3. **is_active safety**: `CompanyAuthorizationService::check()` uses `($user->is_active ?? true)` so it defaults to active if the column hasn't been migrated yet in a production environment. In tests, `RefreshDatabase` runs all migrations including Phase 9B's `add_is_active_to_users_table`.

4. **No competing ownership mechanisms**: `is_owner` lives exclusively on the `company_user` pivot. The `Company::owner()` method queries that pivot — no `owner_id` column was added to `companies`.

5. **Blade integration**: The view composer now passes `$currentUserPermissions` (array of key strings) and `$currentUserIsOwner` (bool) to all company-scoped views. Part 2 UI can use `in_array('sales.create', $currentUserPermissions)` in Blade directly.

6. **Part 2 scope** (not done here): Route guards using `->middleware('company.permission:key')`, the roles management UI (index/create/edit/delete/assign), and navigation-level permission filtering.
