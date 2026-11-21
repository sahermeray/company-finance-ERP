# Phase 9C Architecture Summary — Roles & Permissions System

## 1. Current `company_user` Pivot Schema

From `2026_10_06_181353_create_companies_and_company_user_tables.php`:

| Column       | Type                    | Constraints                     |
|--------------|-------------------------|---------------------------------|
| company_id   | unsignedBigInteger FK   | CASCADE DELETE, NOT NULL        |
| user_id      | unsignedBigInteger FK   | CASCADE DELETE, NOT NULL        |
| created_at   | timestamp               | nullable                        |
| updated_at   | timestamp               | nullable                        |
| UNIQUE       | (company_id, user_id)   |                                 |
| INDEX        | (user_id)               |                                 |

No ownership flag, no role reference — this confirms the schema has no existing permission or ownership concept.

---

## 2. All Controllers and Module/Actions

| Controller | Routes / Actions |
|---|---|
| `AuthenticatedSessionController` | login (create/store), logout (destroy) |
| `DashboardController` | `GET /dashboard` — dashboard.view |
| `CompanySelectionController` | `GET /companies/select` — company selector |
| `CompanyContextController` | `POST /company-context` — switch active company |
| `LocalePreferenceController` | `POST /language` — locale switch |
| `ProductController` | products.index, .create, .store, .show, .edit, .update |
| `InventoryController` | inventory.overview; counts: index/create/store/show/approve/post; adjustments: index/create/store/show/approve/post |
| `CatalogController` | catalog.index; categories.store/update; units.store/update |
| `WarehouseController` | warehouses.index, .create, .store, .show, .edit, .update |
| `PartyController` | customers: index/create/store/edit/update/show/statement/print; suppliers: same |
| `PaymentController` | payments.index, .create, .store, .reverse, .print, .show |
| `PurchaseController` | purchases.index, .create, .store, .show, .post, .print |
| `SalesController` | sales.index, .create, .store, .show, .post, .print, .availability |
| `ExpenseController` | expenses.index, .create, .store, .show, .approve, .post, .settle, .reverse, .destroy, .print |
| `CashBankController` | cash-bank.index, .create, .store, .print, .edit, .update, .show |
| `AccountingJournalController` | journals.index, .show; mappings (view/update) |
| `FinancialReportController` | reports.index; trial-balance, general-ledger, income-statement, balance-sheet, performance-trends (view + print) |
| `CompanySettingsController` | settings.general (view/update), .financial (view/update), .exchange-rates (view/store), .forex-accounts (view/update gain+loss), .fiscal-year (view) |
| `UserController` | users.index, .create, .store, .show, .edit, .update, .activate, .deactivate, .memberships.add, .memberships.remove |

---

## 3. Proposed Database Schema Changes

### Migration B — `create_roles_table` (must run first)
```
roles
  id                bigint PK
  company_id        FK → companies (cascade delete)
  name_en           varchar(100)
  name_ar           varchar(100)
  slug              varchar(100)   — machine key, UNIQUE(company_id, slug)
  description       text nullable
  is_system         boolean default false
  is_protected      boolean default false
  timestamps
```

### Migration A — `add_role_id_to_company_user_table`
```
company_user (modify existing pivot)
  + role_id         nullable FK → roles.id (SET NULL on delete)
  + is_owner        boolean default false NOT NULL
```

### Migration C — `create_permissions_table`
```
permissions
  id        bigint PK
  key       varchar(100) UNIQUE   — e.g. 'sales.create'
  module    varchar(60)
  action    varchar(60)
  name_en   varchar(255)
  name_ar   varchar(255)
  timestamps
```

### Migration D — `create_role_permissions_table`
```
role_permissions
  role_id       FK → roles (cascade delete)
  permission_id FK → permissions (cascade delete)
  UNIQUE(role_id, permission_id)
  no timestamps
```

---

## 4. Complete Permission Catalog (from actual routes)

Derived strictly from routes/web.php and the controllers that handle them.

| Key | Module | Action | Description |
|-----|--------|--------|-------------|
| `dashboard.view` | dashboard | view | View dashboard |
| `products.view` | products | view | View products list and details |
| `products.create` | products | create | Create new products |
| `products.update` | products | update | Edit existing products |
| `inventory.view` | inventory | view | View inventory overview |
| `inventory.adjust` | inventory | adjust | Create/post inventory adjustments |
| `inventory.count` | inventory | count | Create/approve/post stock counts |
| `catalog.view` | catalog | view | View product categories and units |
| `catalog.manage` | catalog | manage | Create/update categories and units |
| `warehouses.view` | warehouses | view | View warehouse list and details |
| `warehouses.create` | warehouses | create | Create new warehouses |
| `warehouses.update` | warehouses | update | Edit existing warehouses |
| `customers.view` | customers | view | View customer list, details, statements |
| `customers.create` | customers | create | Create new customers |
| `customers.update` | customers | update | Edit customer details |
| `suppliers.view` | suppliers | view | View supplier list, details, statements |
| `suppliers.create` | suppliers | create | Create new suppliers |
| `suppliers.update` | suppliers | update | Edit supplier details |
| `sales.view` | sales | view | View sales invoices |
| `sales.create` | sales | create | Create draft sales invoices |
| `sales.post` | sales | post | Post (finalize) sales invoices |
| `purchases.view` | purchases | view | View purchase invoices |
| `purchases.create` | purchases | create | Create draft purchase invoices |
| `purchases.post` | purchases | post | Post (finalize) purchase invoices |
| `payments.view` | payments | view | View payment transactions |
| `payments.create` | payments | create | Record payment transactions |
| `payments.reverse` | payments | reverse | Reverse posted payments |
| `expenses.view` | expenses | view | View expenses |
| `expenses.create` | expenses | create | Create draft expenses |
| `expenses.approve` | expenses | approve | Approve expenses |
| `expenses.post` | expenses | post | Post expenses |
| `expenses.settle` | expenses | settle | Settle outstanding expenses |
| `expenses.reverse` | expenses | reverse | Reverse posted expenses |
| `expenses.delete` | expenses | delete | Delete draft expenses |
| `cash_accounts.view` | cash_accounts | view | View financial accounts (chart of accounts) |
| `cash_accounts.create` | cash_accounts | create | Create new financial accounts |
| `cash_accounts.update` | cash_accounts | update | Edit financial accounts |
| `accounting.journals.view` | accounting | journals.view | View accounting journals |
| `accounting.mappings.view` | accounting | mappings.view | View control account mappings |
| `accounting.mappings.update` | accounting | mappings.update | Update control account mappings |
| `reports.view` | reports | view | View all financial reports |
| `company_settings.view` | company_settings | view | View company settings |
| `company_settings.update` | company_settings | update | Update company settings |
| `users.view` | users | view | View user list and details |
| `users.create` | users | create | Create new users |
| `users.update` | users | update | Edit user profiles |
| `users.deactivate` | users | deactivate | Activate/deactivate users |
| `users.manage_memberships` | users | manage_memberships | Add/remove company memberships |
| `roles.view` | roles | view | View roles and permissions (Phase 9C Part 2) |
| `roles.create` | roles | create | Create custom roles |
| `roles.update` | roles | update | Edit role permissions and names |
| `roles.delete` | roles | delete | Delete custom roles |
| `roles.assign` | roles | assign | Assign roles to users |

---

## 5. Migration Ordering Plan

The constraint is: `roles` table must exist before the FK in `company_user`. `permissions` must exist before `role_permissions`.

```
ORDER:
  1. Migration B — create_roles_table
     (no dependencies on new tables)

  2. Migration A — add_role_id_to_company_user_table
     (needs roles.id to exist for FK)

  3. Migration C — create_permissions_table
     (independent)

  4. Migration D — create_role_permissions_table
     (needs roles.id and permissions.id)
```

Timestamps used:
- B: `2026_11_22_000001_create_roles_table.php`
- A: `2026_11_22_000002_add_role_id_to_company_user_table.php`
- C: `2026_11_22_000003_create_permissions_table.php`
- D: `2026_11_22_000004_create_role_permissions_table.php`

---

## 6. Notes on `is_active` and Production Safety

The `is_active` column on users is added by migration `2026_10_07_000001_add_is_active_to_users_table.php`, which runs in tests via `RefreshDatabase`. In production it won't exist until migrated.

`CompanyAuthorizationService` uses `($user->is_active ?? true) === true` to default to active if the attribute is absent, making it safe in both environments.

---

## 7. No Competing Ownership Mechanisms

Ownership is stored exclusively as `is_owner = true` on the `company_user` pivot row. No separate `owner_id` column is added to `companies`. The pivot is the single source of truth. The `Company::owner()` method queries this pivot.
