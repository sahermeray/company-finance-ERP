<?php

use App\Models\AccountingJournal;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Services\AccountingService;
use App\Services\CompanyContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('posts a balanced journal and calculates account balances from journal lines', function () {
    $company = Company::create(['name' => 'Ledger Co', 'base_currency' => 'SYP']);
    $cash = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1000',
        'name' => 'Cash',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);
    $revenue = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '4000',
        'name' => 'Sales Revenue',
        'account_type' => 'revenue',
        'currency' => 'SYP',
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use ($cash, $revenue): void {
        $journal = app(AccountingService::class)->createDraftJournal([
            'journal_number' => 'JRN-001',
            'entry_date' => '2026-10-10',
            'description' => 'Customer payment',
        ]);
        app(AccountingService::class)->addJournalLine($journal, [
            'financial_account_id' => $cash->id,
            'debit' => '1500',
            'currency' => 'SYP',
            'exchange_rate' => '1',
            'base_currency' => 'SYP',
            'base_debit' => '1500',
            'base_credit' => '0',
        ]);
        app(AccountingService::class)->addJournalLine($journal, [
            'financial_account_id' => $revenue->id,
            'credit' => '1500',
            'currency' => 'SYP',
            'exchange_rate' => '1',
            'base_currency' => 'SYP',
            'base_debit' => '0',
            'base_credit' => '1500',
        ]);

        $posted = app(AccountingService::class)->postJournal($journal);

        expect($posted->status)->toBe('posted')
            ->and($posted->lines)->toHaveCount(2)
            ->and($cash->fresh()->balance())->toBe('1500.0000000000')
            ->and($cash->fresh()->debitTotal())->toBe('1500.0000')
            ->and($cash->fresh()->creditTotal())->toBe('0.0000');
    });
});

it('rejects unbalanced journal posting and zero-value lines', function () {
    $company = Company::create(['name' => 'Unbalanced Ltd']);
    $cash = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1000',
        'name' => 'Cash',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);
    $revenue = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '4000',
        'name' => 'Sales Revenue',
        'account_type' => 'revenue',
        'currency' => 'SYP',
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use ($cash, $revenue): void {
        $service = app(AccountingService::class);
        $journal = $service->createDraftJournal(['journal_number' => 'JRN-UNBALANCED', 'entry_date' => '2026-10-10']);
        $service->addJournalLine($journal, ['financial_account_id' => $cash->id, 'debit' => '1000']);
        $service->addJournalLine($journal, ['financial_account_id' => $revenue->id, 'credit' => '500']);

        expect(fn () => $service->postJournal($journal))->toThrow(ValidationException::class);

        $zeroJournal = $service->createDraftJournal(['journal_number' => 'JRN-ZERO', 'entry_date' => '2026-10-10']);
        expect(fn () => $service->addJournalLine($zeroJournal, ['financial_account_id' => $cash->id, 'debit' => '0']))
            ->toThrow(ValidationException::class);
    });
});

it('rejects negative and cross-company journal data', function () {
    $companyA = Company::create(['name' => 'Company A']);
    $companyB = Company::create(['name' => 'Company B']);
    $cashA = FinancialAccount::create([
        'company_id' => $companyA->id,
        'code' => '1000',
        'name' => 'Cash A',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);
    $cashB = FinancialAccount::create([
        'company_id' => $companyB->id,
        'code' => '1000',
        'name' => 'Cash B',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);

    app(CompanyContextService::class)->runForCompany($companyA, function () use ($cashA, $cashB): void {
        $service = app(AccountingService::class);
        $journal = $service->createDraftJournal(['journal_number' => 'JRN-NEG', 'entry_date' => '2026-10-10']);
        expect(fn () => $service->addJournalLine($journal, ['financial_account_id' => $cashA->id, 'debit' => '-100']))
            ->toThrow(ValidationException::class);

        $mixedJournal = $service->createDraftJournal(['journal_number' => 'JRN-MIXED', 'entry_date' => '2026-10-10']);
        $service->addJournalLine($mixedJournal, ['financial_account_id' => $cashA->id, 'debit' => '100']);
        expect(fn () => $service->addJournalLine($mixedJournal, ['financial_account_id' => $cashB->id, 'credit' => '100']))
            ->toThrow(ValidationException::class);
    });
});

it('keeps posted journals immutable and creates a reversal with opposite sides', function () {
    $company = Company::create(['name' => 'Reverse Co']);
    $cash = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1000',
        'name' => 'Cash',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);
    $payable = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '2000',
        'name' => 'Supplier Payable',
        'account_type' => 'liability',
        'currency' => 'SYP',
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use ($cash, $payable): void {
        $service = app(AccountingService::class);
        $journal = $service->createDraftJournal(['journal_number' => 'JRN-REV', 'entry_date' => '2026-10-10']);
        $service->addJournalLine($journal, ['financial_account_id' => $cash->id, 'debit' => '2500']);
        $service->addJournalLine($journal, ['financial_account_id' => $payable->id, 'credit' => '2500']);
        $posted = $service->postJournal($journal);

        $originalDescription = $posted->description;
        expect(fn () => tap($posted, fn (AccountingJournal $journal) => $journal->description = 'tampered')->save())
            ->toThrow(ValidationException::class);
        expect($posted->fresh()->description)->toBe($originalDescription);

        $reversal = $service->reverseJournal($posted->fresh());
        expect($reversal->status)->toBe('posted')
            ->and($reversal->reversal_of_journal_id)->toBe($posted->id)
            ->and((string) $reversal->lines()->where('debit', '>', 0)->sum('debit'))->toBe('2500')
            ->and((string) $reversal->lines()->where('credit', '>', 0)->sum('credit'))->toBe('2500');
    });
});

it('preserves USD transaction values and exchange rates in the journal', function () {
    $company = Company::create(['name' => 'USD Ledger', 'base_currency' => 'SYP']);
    $cash = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1000-USD',
        'name' => 'Cash USD',
        'account_type' => 'asset',
        'currency' => 'USD',
    ]);
    $revenue = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '4000-USD',
        'name' => 'Revenue USD',
        'account_type' => 'revenue',
        'currency' => 'USD',
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use ($cash, $revenue): void {
        $service = app(AccountingService::class);
        $journal = $service->createDraftJournal(['journal_number' => 'JRN-USD', 'entry_date' => '2026-10-10']);
        $service->addJournalLine($journal, [
            'financial_account_id' => $cash->id,
            'debit' => '1000',
            'currency' => 'USD',
            'exchange_rate' => '12000',
            'base_currency' => 'SYP',
            'base_debit' => '12000000',
        ]);
        $service->addJournalLine($journal, [
            'financial_account_id' => $revenue->id,
            'credit' => '1000',
            'currency' => 'USD',
            'exchange_rate' => '12000',
            'base_currency' => 'SYP',
            'base_credit' => '12000000',
        ]);

        $posted = $service->postJournal($journal);
        expect($posted->lines->first()->currency)->toBe('USD')
            ->and($posted->lines->first()->exchange_rate)->toBe('12000.0000000000')
            ->and($posted->lines->first()->base_debit)->toBe('12000000.0000');
    });
});

it('supports debit-on-asset and credit-on-liability account behavior', function () {
    $company = Company::create(['name' => 'Balance Behavior']);
    $asset = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1000',
        'name' => 'Asset',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);
    $liability = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '2000',
        'name' => 'Payable',
        'account_type' => 'liability',
        'currency' => 'SYP',
    ]);
    $expense = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '5000',
        'name' => 'Expense',
        'account_type' => 'expense',
        'currency' => 'SYP',
    ]);
    $revenue = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '4000',
        'name' => 'Revenue',
        'account_type' => 'revenue',
        'currency' => 'SYP',
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use ($asset, $liability, $expense, $revenue): void {
        $service = app(AccountingService::class);

        $assetJournal = $service->createDraftJournal(['journal_number' => 'JRN-ASSET', 'entry_date' => '2026-10-10']);
        $service->addJournalLine($assetJournal, ['financial_account_id' => $asset->id, 'debit' => '100']);
        $service->addJournalLine($assetJournal, ['financial_account_id' => $revenue->id, 'credit' => '100']);
        $service->postJournal($assetJournal);

        $liabilityJournal = $service->createDraftJournal(['journal_number' => 'JRN-LIAB', 'entry_date' => '2026-10-10']);
        $service->addJournalLine($liabilityJournal, ['financial_account_id' => $liability->id, 'credit' => '200']);
        $service->addJournalLine($liabilityJournal, ['financial_account_id' => $expense->id, 'debit' => '200']);
        $service->postJournal($liabilityJournal);

        expect($asset->fresh()->balance())->toBe('100.0000000000')
            ->and($liability->fresh()->balance())->toBe('-200.0000000000')
            ->and($expense->fresh()->balance())->toBe('200.0000000000');
    });
});

it('prevents a user from posting to another company account and leaves no partial records', function () {
    $companyA = Company::create(['name' => 'A']);
    $companyB = Company::create(['name' => 'B']);
    $accountA = FinancialAccount::create([
        'company_id' => $companyA->id,
        'code' => '1000',
        'name' => 'Cash A',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);
    $accountB = FinancialAccount::create([
        'company_id' => $companyB->id,
        'code' => '1000',
        'name' => 'Cash B',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);

    app(CompanyContextService::class)->runForCompany($companyA, function () use ($accountA, $accountB): void {
        $service = app(AccountingService::class);
        $journal = $service->createDraftJournal(['journal_number' => 'JRN-ACCESS', 'entry_date' => '2026-10-10']);
        $service->addJournalLine($journal, ['financial_account_id' => $accountA->id, 'debit' => '50']);

        expect(fn () => $service->addJournalLine($journal, ['financial_account_id' => $accountB->id, 'credit' => '50']))
            ->toThrow(ValidationException::class);
        expect(AccountingJournal::query()->where('journal_number', 'JRN-ACCESS')->count())->toBe(1)
            ->and(JournalLine::query()->where('accounting_journal_id', $journal->id)->count())->toBe(1);
    });
});

// ---------------------------------------------------------------------------
// FinancialAccount multi-currency balance semantics
// ---------------------------------------------------------------------------

it('computes native-currency and base-currency balances separately for a multi-currency account', function () {
    $company = Company::create(['name' => 'MultiBal', 'base_currency' => 'SYP']);
    $account = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '1000',
        'name' => 'Mixed Cash', 'account_type' => 'asset', 'currency' => 'SYP',
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use ($account): void {
        $journal = AccountingJournal::create([
            'company_id' => $account->company_id,
            'journal_number' => 'JRN-MIXBAL',
            'entry_date' => '2026-10-10',
            'status' => 'posted',
            'posted_at' => now(),
        ]);

        JournalLine::create([
            'accounting_journal_id' => $journal->id,
            'financial_account_id' => $account->id,
            'line_number' => 1,
            'debit' => '1500', 'credit' => '0',
            'currency' => 'SYP', 'exchange_rate' => '1',
            'base_currency' => 'SYP',
            'base_debit' => '1500', 'base_credit' => '0',
        ]);

        JournalLine::create([
            'accounting_journal_id' => $journal->id,
            'financial_account_id' => $account->id,
            'line_number' => 2,
            'debit' => '1000', 'credit' => '0',
            'currency' => 'USD', 'exchange_rate' => '12000',
            'base_currency' => 'SYP',
            'base_debit' => '12000000', 'base_credit' => '0',
        ]);

        $draftJournal = AccountingJournal::create([
            'company_id' => $account->company_id,
            'journal_number' => 'JRN-MIXBAL-DRAFT',
            'entry_date' => '2026-10-10',
            'status' => 'draft',
        ]);

        JournalLine::create([
            'accounting_journal_id' => $draftJournal->id,
            'financial_account_id' => $account->id,
            'line_number' => 1,
            'debit' => '500', 'credit' => '0',
            'currency' => 'SYP', 'exchange_rate' => '1',
            'base_currency' => 'SYP',
            'base_debit' => '500', 'base_credit' => '0',
        ]);

        JournalLine::create([
            'accounting_journal_id' => $draftJournal->id,
            'financial_account_id' => $account->id,
            'line_number' => 2,
            'debit' => '2000', 'credit' => '0',
            'currency' => 'USD', 'exchange_rate' => '12000',
            'base_currency' => 'SYP',
            'base_debit' => '24000000', 'base_credit' => '0',
        ]);

        $fresh = $account->fresh();
        $balances = $fresh->balanceByCurrency();

        // SYP native balance = 1500 (USD line excluded)
        expect(bccomp($fresh->nativeCurrencyBalance('SYP'), '1500', 10))->toBe(0);
        // USD native balance = 1000 (SYP line excluded)
        expect(bccomp($fresh->nativeCurrencyBalance('USD'), '1000', 10))->toBe(0);
        // Draft journals do not create a currency balance.
        expect(array_keys($balances))->toEqualCanonicalizing(['SYP', 'USD']);
        expect(bccomp($balances['SYP']['debit'], '1500', 10))->toBe(0)
            ->and(bccomp($balances['USD']['debit'], '1000', 10))->toBe(0);
        // Base-currency balance = 1500 + 12000000 = 12001500
        expect(bccomp($fresh->baseCurrencyBalance(), '12001500', 10))->toBe(0);
    });
});

it('excludes draft journal lines from every financial account balance', function () {
    $company = Company::create(['name' => 'PostedBalances', 'base_currency' => 'SYP']);
    $cash = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1000',
        'name' => 'Cash',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);
    $offset = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '3000',
        'name' => 'Equity',
        'account_type' => 'equity',
        'currency' => 'SYP',
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use ($cash, $offset): void {
        $accounting = app(AccountingService::class);

        $postedJournal = $accounting->createDraftJournal([
            'journal_number' => 'JRN-POSTED-BALANCE',
            'entry_date' => '2026-10-10',
        ]);
        $accounting->addJournalLine($postedJournal, [
            'financial_account_id' => $cash->id,
            'debit' => '125',
            'currency' => 'SYP',
        ]);
        $accounting->addJournalLine($postedJournal, [
            'financial_account_id' => $offset->id,
            'credit' => '125',
            'currency' => 'SYP',
        ]);
        $accounting->postJournal($postedJournal);

        $draftJournal = $accounting->createDraftJournal([
            'journal_number' => 'JRN-DRAFT-BALANCE',
            'entry_date' => '2026-10-10',
        ]);
        $accounting->addJournalLine($draftJournal, [
            'financial_account_id' => $cash->id,
            'debit' => '900',
            'currency' => 'SYP',
        ]);
        $accounting->addJournalLine($draftJournal, [
            'financial_account_id' => $cash->id,
            'credit' => '40',
            'currency' => 'SYP',
        ]);

        $cancelledJournal = AccountingJournal::create([
            'company_id' => $cash->company_id,
            'journal_number' => 'JRN-CANCELLED-BALANCE',
            'entry_date' => '2026-10-10',
            'status' => 'cancelled',
        ]);
        JournalLine::create([
            'accounting_journal_id' => $cancelledJournal->id,
            'financial_account_id' => $cash->id,
            'line_number' => 1,
            'debit' => '75',
            'credit' => '0',
            'currency' => 'SYP',
            'exchange_rate' => '1',
            'base_currency' => 'SYP',
            'base_debit' => '75',
            'base_credit' => '0',
        ]);

        $fresh = $cash->fresh();
        $currencyBalances = $fresh->balanceByCurrency();

        expect([
            'balance' => bccomp($fresh->balance(), '125', 10),
            'debit_total' => bccomp($fresh->debitTotal(), '125', 10),
            'credit_total' => bccomp($fresh->creditTotal(), '0', 10),
            'native_balance' => bccomp($fresh->nativeCurrencyBalance('SYP'), '125', 10),
            'currency_count' => count($currencyBalances),
            'currency_debit' => bccomp($currencyBalances['SYP']['debit'] ?? '0', '125', 10),
            'currency_credit' => bccomp($currencyBalances['SYP']['credit'] ?? '0', '0', 10),
            'currency_balance' => bccomp($currencyBalances['SYP']['balance'] ?? '0', '125', 10),
            'base_balance' => bccomp($fresh->baseCurrencyBalance(), '125', 10),
        ])->toBe([
            'balance' => 0,
            'debit_total' => 0,
            'credit_total' => 0,
            'native_balance' => 0,
            'currency_count' => 1,
            'currency_debit' => 0,
            'currency_credit' => 0,
            'currency_balance' => 0,
            'base_balance' => 0,
        ]);
    });
});

it('counts posted reversal journals so they offset the original posted journal', function () {
    $company = Company::create(['name' => 'ReversalBalances', 'base_currency' => 'SYP']);
    $cash = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '1000',
        'name' => 'Cash',
        'account_type' => 'asset',
        'currency' => 'SYP',
    ]);
    $payable = FinancialAccount::create([
        'company_id' => $company->id,
        'code' => '2000',
        'name' => 'Payable',
        'account_type' => 'liability',
        'currency' => 'SYP',
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use ($cash, $payable): void {
        $accounting = app(AccountingService::class);
        $journal = $accounting->createDraftJournal([
            'journal_number' => 'JRN-REVERSAL-BALANCE',
            'entry_date' => '2026-10-10',
        ]);
        $accounting->addJournalLine($journal, [
            'financial_account_id' => $cash->id,
            'debit' => '250',
        ]);
        $accounting->addJournalLine($journal, [
            'financial_account_id' => $payable->id,
            'credit' => '250',
        ]);
        $posted = $accounting->postJournal($journal);

        $reversal = $accounting->reverseJournal($posted);

        expect($posted->fresh()->status)->toBe('posted')
            ->and($reversal->status)->toBe('posted')
            ->and((int) $reversal->reversal_of_journal_id)->toBe((int) $posted->id);

        $fresh = $cash->fresh();

        expect(bccomp($fresh->debitTotal(), '250', 10))->toBe(0)
            ->and(bccomp($fresh->creditTotal(), '250', 10))->toBe(0)
            ->and(bccomp($fresh->balance(), '0', 10))->toBe(0)
            ->and(bccomp($fresh->nativeCurrencyBalance('SYP'), '0', 10))->toBe(0)
            ->and(bccomp($fresh->baseCurrencyBalance(), '0', 10))->toBe(0);
    });
});

it('agrees across all three balance methods for a single-currency account', function () {
    $company = Company::create(['name' => 'SingleBal', 'base_currency' => 'SYP']);
    $account = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '1000',
        'name' => 'Cash', 'account_type' => 'asset', 'currency' => 'SYP',
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use ($account): void {
        $journal = AccountingJournal::create([
            'company_id' => $account->company_id,
            'journal_number' => 'JRN-SINGLE',
            'entry_date' => '2026-10-10',
            'status' => 'posted',
            'posted_at' => now(),
        ]);

        JournalLine::create([
            'accounting_journal_id' => $journal->id,
            'financial_account_id' => $account->id,
            'line_number' => 1,
            'debit' => '1500', 'credit' => '0',
            'currency' => 'SYP', 'exchange_rate' => '1',
            'base_currency' => 'SYP',
            'base_debit' => '1500', 'base_credit' => '0',
        ]);

        $fresh = $account->fresh();

        // For a single-currency account at rate 1, all three methods agree.
        expect(bccomp($fresh->balance(), $fresh->baseCurrencyBalance(), 10))->toBe(0);
        expect(bccomp($fresh->nativeCurrencyBalance('SYP'), $fresh->baseCurrencyBalance(), 10))->toBe(0);
    });
});

// ---------------------------------------------------------------------------
// Reversal journal referential integrity
// ---------------------------------------------------------------------------

it('prevents deleting a posted journal through Eloquent on all engines', function () {
    $company = Company::create(['name' => 'JournalDelete', 'base_currency' => 'SYP']);
    $account = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '1000',
        'name' => 'Cash', 'account_type' => 'asset', 'currency' => 'SYP',
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use ($account): void {
        $journal = AccountingJournal::create([
            'company_id' => $account->company_id,
            'journal_number' => 'JRN-DEL',
            'entry_date' => '2026-10-10',
            'status' => 'posted',
            'posted_at' => now(),
        ]);

        // The Eloquent deleting hook blocks deletion of posted journals on every engine,
        // including SQLite where the FK action remains SET NULL.
        expect(fn () => $journal->delete())->toThrow(ValidationException::class);
    });
});

it('reversal journal reversal_of_journal_id points to the correct original', function () {
    $company = Company::create(['name' => 'RevLink', 'base_currency' => 'SYP']);
    $cash = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '1000',
        'name' => 'Cash', 'account_type' => 'asset', 'currency' => 'SYP',
    ]);
    $payable = FinancialAccount::create([
        'company_id' => $company->id, 'code' => '2000',
        'name' => 'Payable', 'account_type' => 'liability', 'currency' => 'SYP',
    ]);

    app(CompanyContextService::class)->runForCompany($company, function () use ($cash, $payable): void {
        $service = app(AccountingService::class);
        $journal = $service->createDraftJournal(['journal_number' => 'JRN-LINK', 'entry_date' => '2026-10-10']);
        $service->addJournalLine($journal, ['financial_account_id' => $cash->id, 'debit' => '2500']);
        $service->addJournalLine($journal, ['financial_account_id' => $payable->id, 'credit' => '2500']);
        $posted = $service->postJournal($journal);

        $reversal = $service->reverseJournal($posted->fresh());

        // Integer-cast equality confirms the reversal points to the exact original.
        expect((int) $reversal->reversal_of_journal_id)->toBe((int) $posted->id);
    });
});
