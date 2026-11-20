# Phase 9A — Company Settings UI: Requirements

## Assumptions

The following were inferred from the source code and are noted explicitly for design review:

1. **No owner/admin distinction exists** in the current schema. The `company_user` pivot table has no `role` or `is_owner` column; `User` has no role attribute. Authorization for this phase will be scoped to any authenticated user who belongs to the active company via `CompanyAccessService`. A `created_by` or first-registered concept cannot be reliably derived without a schema change. **Assumption:** a minimal temporary authorization gate will treat all company members as authorized until Phase 9C introduces roles. The design must justify this or propose a minimal safe alternative.

2. **No company logo column exists** in the `companies` table. The existing `Attachment` model and polymorphic `attachmentables` table provide the established file-storage pattern. Logo upload will follow that pattern rather than adding a bare `logo_path` column. If the design determines this is too complex for Phase 9A, the logo section must be explicitly deferred.

3. **Supported currencies are SYP and USD only.** This is confirmed by the multi-currency migration defaulting to `SYP` and the exchange-rate lookup pattern. No new currencies will be introduced.

4. **No fiscal year implementation exists.** A grep across all migrations found no `fiscal_year`, `financial_year`, or `period` table. The Financial Year Settings section will render an informational notice rather than functional controls.

5. **The base-currency change restriction must be based on posted journals**, not merely the presence of any records. The `accounting_journals` table has a `status` column with the value `'posted'`. Draft journals do not constitute posted financial history.

6. **Exchange-rate convention:** one unit of foreign currency expressed in base currency units (e.g. `rate = 1500` means 1 USD = 1500 SYP). This is confirmed by the exchange-rate help text in `lang/en/ui.php`.

7. **Exchange Gain account** must be of type `revenue` or `income`. **Exchange Loss account** must be of type `expense`. Both must be active, belong to the active company, and use the company's base currency. This matches the pattern in `FinancialAccount::VALID_ACCOUNT_TYPES` and the existing forex account migration.

8. **Navigation entry already exists** (`ui.navigation.settings` / `ui.navigation.administration`). The nav config at `config/navigation.php` already has a `company-settings` entry in the Administration group, currently without a `route` key. The route just needs to be wired.

9. **Layout pattern:** all authenticated, company-context pages use `layouts.inventory` (which extends `layouts.app`), with sections yielding `page-content`. The settings UI will follow the same pattern.

10. **Form Requests** are used throughout the project (`SwitchCompanyRequest`, `LoginRequest`, etc.). Settings mutations will use Form Requests.

---

## Summary

Phase 9A adds a Company Settings section accessible at **Administration → Company Settings** in the existing navigation sidebar. Authorized users can view and edit general company information, financial settings (base currency), exchange rates, and forex gain/loss account mappings. The UI must respect the application's existing design system, support Arabic (RTL) and English (LTR), and be fully responsive. No new roles, permissions, or fiscal year logic will be introduced. All changes are scoped to the active company; no cross-company data access is permitted.

---

## Functional Requirements

### FR-1: Navigation

- **FR-1.1** The Administration group in `config/navigation.php` has a `company-settings` item with no `route`. Add a route binding so the item renders as an active link rather than a "coming soon" badge.
- **FR-1.2** The settings area is reachable only while an active company context is set (protected by `company.context` middleware).
- **FR-1.3** The settings interface is organized into discrete sections with their own sub-navigation or tabbed layout: General Information, Financial Settings, Exchange Rates, Foreign Exchange Accounts, and Financial Year (informational).

### FR-2: Authorization

- **FR-2.1** Any authenticated user who is a member of the active company may access the settings pages (read and write), because no owner/administrator distinction exists in the current schema.
- **FR-2.2** All queries and updates are scoped to the company resolved from `CompanyContextService::requireCurrent()`. The `company_id` is never accepted from the request body.
- **FR-2.3** Route group is protected by `auth` and `company.context` middleware, consistent with all other company-scoped routes.

### FR-3: General Information

- **FR-3.1** Display and allow editing of: `name` (required), `legal_name` (optional), `tax_identifier` (optional), `email` (optional), `phone` (optional), `address` (optional), `country_code` (optional), `timezone` (optional).
- **FR-3.2** Company `name` is required; maximum 255 characters.
- **FR-3.3** `email`, when provided, must be a valid email format.
- **FR-3.4** `timezone` must be a valid PHP/IANA timezone identifier when provided. Present as a select populated from `timezone_identifiers_list()` or a curated subset.
- **FR-3.5** Submitting the form with optional fields left blank must not overwrite existing values with null; it must preserve them. (Fields omitted from the request are not updated; fields explicitly cleared are set to null only if the column is nullable.)
- **FR-3.6** Display a success notification after a save. Display field-level validation errors inline.
- **FR-3.7** Company logo upload is **deferred** if no logo column exists in the schema and adding a polymorphic attachment relationship to companies is considered out of scope for Phase 9A. This is a design decision to confirm. If included, the upload must: accept only `image/jpeg`, `image/png`, `image/webp`; enforce a 2 MB file size limit; store via the existing `Attachment` model and polymorphic pattern; never expose the raw storage path in the response.

### FR-4: Financial Settings — Base Currency

- **FR-4.1** Display the company's current `base_currency`.
- **FR-4.2** Allow selecting a new base currency from the list of supported currencies (SYP, USD only — no new currencies).
- **FR-4.3** Changing the base currency is **blocked** when the company has at least one `AccountingJournal` with `status = 'posted'`. Draft journals do not constitute a restriction.
- **FR-4.4** When the currency change is blocked, display a clear, non-dismissable explanation stating why (posted financial history exists) and what the user would need to do to proceed (this is not supported once posting has occurred).
- **FR-4.5** When no posted journals exist, the user may change the base currency after confirming a warning dialog. The warning must state that this change affects all future transactions and cannot be undone once posting begins.
- **FR-4.6** A successful currency change updates only `companies.base_currency`. No existing accounting records are modified.
- **FR-4.7** The base currency field and financial settings are presented separately from general information with their own save action.

### FR-5: Exchange Rates

- **FR-5.1** List all exchange rate records for the active company, ordered by `effective_at` descending. Each row shows: foreign `currency`, `base_currency`, `rate`, `effective_at`, and `source`.
- **FR-5.2** Provide filters: by foreign `currency` (all / SYP / USD) and by `effective_at` date range.
- **FR-5.3** Allow adding a new exchange rate record. The form collects: `currency` (required; must differ from the company's base currency), `rate` (required; decimal, > 0, stored using BCMath-safe decimal), `effective_at` (required; must be a valid datetime; cannot be in the distant future beyond a configurable threshold, e.g. 1 year from today).
- **FR-5.4** `base_currency` on a new rate record is always set to the company's current `base_currency` from the server side; it is not accepted from the request.
- **FR-5.5** Adding a new rate never modifies or deletes existing historical rate records.
- **FR-5.6** `rate` must be strictly greater than zero (BCMath `bccomp($rate, '0', 10) > 0`). Submit with zero or negative value returns a validation error.
- **FR-5.7** The `currency` field must not equal the company's `base_currency` (no self-rate).
- **FR-5.8** The `source` field is set to `'manual'` for all rates entered through the UI. It is not user-editable.
- **FR-5.9** The `created_by` field is set from `Auth::id()` on the server side; it is not accepted from the request.
- **FR-5.10** Display the most recent effective rate per currency prominently above the full history list.
- **FR-5.11** No rate editing or deletion is permitted through the UI (rates are historical, immutable records).

### FR-6: Foreign Exchange Accounts

- **FR-6.1** Display the currently configured `exchange_gain_account_id` and `exchange_loss_account_id`. Show account code, name, type, and currency for each.
- **FR-6.2** Show "not configured" with an amber indicator when either field is null.
- **FR-6.3** Allow selecting a new Exchange Gain account from the active company's financial accounts where: `account_type` is `'revenue'` or `'income'`, `is_active = true`, and `currency = company.base_currency`.
- **FR-6.4** Allow selecting a new Exchange Loss account from the active company's financial accounts where: `account_type = 'expense'`, `is_active = true`, and `currency = company.base_currency`.
- **FR-6.5** Inactive accounts and accounts in the wrong type or currency must not appear in the selection lists.
- **FR-6.6** Accounts belonging to other companies must not appear (enforced by the `TenantScoped` global scope; the controller must not bypass it).
- **FR-6.7** Each account mapping (gain and loss) has its own save action. They are updated independently.
- **FR-6.8** This section must not change any journal generation logic or accounting service behavior.

### FR-7: Financial Year Settings

- **FR-7.1** The Financial Year tab/section renders an informational notice: fiscal year configuration does not exist in the current schema and will be delivered in a future phase.
- **FR-7.2** No database migrations, models, or accounting logic related to fiscal years are added in this phase.

### FR-8: UI and UX

- **FR-8.1** The interface extends `layouts.inventory` and uses all existing CSS components (`panel`, `button`, `form-control`, `form-label`, etc.).
- **FR-8.2** Arabic is the default locale; English is fully supported. All new strings are added to both `lang/ar/ui.php` and `lang/en/ui.php` under a `settings` key.
- **FR-8.3** The layout is responsive and renders correctly at mobile, tablet, and desktop widths.
- **FR-8.4** RTL direction (`dir="rtl"`) is applied by the existing `layouts.app` template based on locale; no special per-component handling is required beyond using logical CSS properties or the existing CSS classes.
- **FR-8.5** Section navigation (sub-nav links or tabs) makes the active section visually distinct.
- **FR-8.6** Each settings section has its own save button. There is no single "save everything" form.
- **FR-8.7** Success messages are shown via `session('status')`, consistent with the existing layout pattern.
- **FR-8.8** Validation errors are shown via the existing `$errors` bag rendered in the layout.
- **FR-8.9** Sensitive changes (base currency change) must present a confirmation step (e.g. a confirmation checkbox or modal) before the form is submitted.
- **FR-8.10** When a setting is locked (e.g. base currency after posting), the input is rendered as read-only with an explanatory notice rather than simply hiding the field.

---

## Non-Functional Requirements

- **NFR-1 Security:** `company_id` is never read from the request body. All account lookups use the `TenantScoped` global scope; the scope must not be bypassed.
- **NFR-2 Decimal precision:** All exchange rate arithmetic uses BCMath at 10 decimal places, consistent with the existing `ExchangeRate` cast (`'decimal:10'`) and the patterns in `AccountingService`.
- **NFR-3 Database transactions:** Updates that touch multiple related records (e.g. a future logo-attachment + company-record save) are wrapped in `DB::transaction()`.
- **NFR-4 No modification of posted data:** No controller or service introduced in this phase may update any posted `AccountingJournal`, `JournalLine`, or related financial record.
- **NFR-5 Testability:** Business rules (posted-journal check, rate validation, account eligibility) must be extractable into a service class so they can be unit-tested independently of HTTP.

---

## Acceptance Criteria

1. Navigating to **Administration → Company Settings** in the sidebar takes the user to the settings index without a 404 or "coming soon" state.
2. An unauthenticated request to any settings route redirects to `/login`.
3. A request without an active company context (no `active_company_id` in session) is handled by `EnsureActiveCompany` middleware and does not reach the settings controller.
4. Saving valid general information (name, email, phone, address, timezone) updates the `companies` row for the active company and shows a success message.
5. Submitting the general information form with the optional `email` field empty does not set `email` to null if it was previously populated; the existing value is preserved.
6. Submitting general information with an invalid email address returns a validation error on the `email` field.
7. The base currency field shows the company's current `base_currency`.
8. When the company has at least one journal with `status = 'posted'`, the base currency selector is disabled and a clear explanation is displayed; the form cannot be submitted.
9. When the company has no posted journals, the user can select a new base currency, sees a warning, confirms, and the `base_currency` column is updated with no other records modified.
10. The exchange rates list shows all `ExchangeRate` records for the active company, ordered by `effective_at` descending.
11. Submitting a new exchange rate with `rate = 0` returns a validation error.
12. Submitting a new exchange rate with `currency` equal to the company's `base_currency` returns a validation error.
13. Submitting a valid new exchange rate creates a new `ExchangeRate` row; existing rows are unchanged.
14. The `base_currency`, `source`, and `created_by` fields of a new exchange rate are set server-side and cannot be injected via the request body.
15. The forex gain account selector shows only active accounts of type `revenue` or `income` in the company's base currency that belong to the active company.
16. The forex loss account selector shows only active accounts of type `expense` in the company's base currency that belong to the active company.
17. Saving a forex gain account updates `companies.exchange_gain_account_id`; saving a forex loss account updates `companies.exchange_loss_account_id`. No journal or accounting service logic is invoked.
18. Attempting to select an account from another company (by crafting a request with a foreign account ID) results in a validation error or 404, not a successful update.
19. The Financial Year section renders an informational notice; no form controls for fiscal year configuration are present.
20. All UI text in every new view has corresponding entries in both `lang/en/ui.php` and `lang/ar/ui.php`.
21. With locale set to Arabic, the page direction attribute is `rtl` and all layout elements render in RTL order.
22. Each settings section has its own distinct save button; there is no single global save.
23. A locked setting (base currency when posting exists) renders a visible explanation notice, not merely a disabled input with no context.

---

## Out of Scope

- User management, roles, and permissions (Phase 9C).
- Granular per-user authorization within company settings (Phase 9C).
- Automatic or manual conversion of historical balances when changing base currency.
- Adding currencies beyond SYP and USD.
- Editing or deleting historical exchange rate records.
- Fiscal year / accounting period configuration or database structures (future phase).
- Company logo upload (deferred pending design decision; see Assumption 2).
- Multi-company administration or cross-company settings.
- Any change to `AccountingService`, `FinancialReportingService`, or journal generation logic.
