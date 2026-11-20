<?php

namespace App\Http\Controllers;

use App\Models\FinancialAccount;
use App\Services\CompanyContextService;
use App\Services\FinancialReportingService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

readonly class FinancialReportController
{
    // Constructor injection is used here (unlike AccountingJournalController which resolves services per-action) to avoid nine repeated app() calls.
    public function __construct(private readonly FinancialReportingService $reports) {}

    public function index(): View
    {
        app(CompanyContextService::class)->requireCurrent();

        return view('reports.index');
    }

    public function trialBalance(Request $request): View|RedirectResponse
    {
        $company = app(CompanyContextService::class)->requireCurrent();

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $dateFrom = $filters['date_from'] ?? Carbon::now()->startOfMonth()->toDateString();
        $dateTo = $filters['date_to'] ?? Carbon::now()->endOfMonth()->toDateString();

        $report = $this->reports->trialBalance($company, $dateFrom, $dateTo);

        return view('reports.trial-balance', [
            'report' => $report,
            'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
        ]);
    }

    public function generalLedger(Request $request): View|RedirectResponse
    {
        $company = app(CompanyContextService::class)->requireCurrent();

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'account_id' => [
                'nullable',
                'integer',
                Rule::exists('financial_accounts', 'id')->where('company_id', $company->getKey()),
            ],
        ]);

        $dateFrom = $filters['date_from'] ?? Carbon::now()->startOfMonth()->toDateString();
        $dateTo = $filters['date_to'] ?? Carbon::now()->endOfMonth()->toDateString();
        $accountId = isset($filters['account_id']) ? (int) $filters['account_id'] : null;

        $accounts = FinancialAccount::where('company_id', $company->getKey())
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $report = $this->reports->generalLedger($company, $dateFrom, $dateTo, $accountId);

        return view('reports.general-ledger', [
            'report' => $report,
            'accounts' => $accounts,
            'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo, 'account_id' => $accountId],
        ]);
    }

    public function incomeStatement(Request $request): View|RedirectResponse
    {
        $company = app(CompanyContextService::class)->requireCurrent();

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'comparison_from' => ['nullable', 'date', 'required_with:comparison_to'],
            'comparison_to' => ['nullable', 'date', 'after_or_equal:comparison_from', 'required_with:comparison_from'],
        ]);

        $dateFrom = $filters['date_from'] ?? Carbon::now()->startOfMonth()->toDateString();
        $dateTo = $filters['date_to'] ?? Carbon::now()->endOfMonth()->toDateString();
        $comparisonFrom = $filters['comparison_from'] ?? null;
        $comparisonTo = $filters['comparison_to'] ?? null;

        $report = $this->reports->incomeStatement($company, $dateFrom, $dateTo, $comparisonFrom, $comparisonTo);

        return view('reports.income-statement', [
            'report' => $report,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'comparison_from' => $comparisonFrom,
                'comparison_to' => $comparisonTo,
            ],
        ]);
    }

    public function balanceSheet(Request $request): View|RedirectResponse
    {
        $company = app(CompanyContextService::class)->requireCurrent();

        $filters = $request->validate([
            'as_of_date' => ['nullable', 'date'],
            'period_start' => ['nullable', 'date', 'before_or_equal:as_of_date'],
        ]);

        $asOfDate = $filters['as_of_date'] ?? Carbon::now()->toDateString();
        $periodStart = $filters['period_start'] ?? null;

        $report = $this->reports->balanceSheet($company, $asOfDate, $periodStart);

        return view('reports.balance-sheet', [
            'report' => $report,
            'filters' => ['as_of_date' => $asOfDate, 'period_start' => $periodStart],
        ]);
    }

    public function performanceTrends(Request $request): View|RedirectResponse
    {
        $company = app(CompanyContextService::class)->requireCurrent();

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $dateFrom = $filters['date_from'] ?? Carbon::now()->startOfYear()->toDateString();
        $dateTo = $filters['date_to'] ?? Carbon::now()->toDateString();

        $report = $this->reports->incomeStatementTrend($company, $dateFrom, $dateTo);

        return view('reports.performance-trends', [
            'report' => $report,
            'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
        ]);
    }

    public function printTrialBalance(Request $request): View|RedirectResponse
    {
        $company = app(CompanyContextService::class)->requireCurrent();

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $dateFrom = $filters['date_from'] ?? Carbon::now()->startOfMonth()->toDateString();
        $dateTo = $filters['date_to'] ?? Carbon::now()->endOfMonth()->toDateString();

        $report = $this->reports->trialBalance($company, $dateFrom, $dateTo);

        return view('reports.trial-balance-print', [
            'report' => $report,
            'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
        ]);
    }

    public function printGeneralLedger(Request $request): View|RedirectResponse
    {
        $company = app(CompanyContextService::class)->requireCurrent();

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'account_id' => [
                'nullable',
                'integer',
                Rule::exists('financial_accounts', 'id')->where('company_id', $company->getKey()),
            ],
        ]);

        $dateFrom = $filters['date_from'] ?? Carbon::now()->startOfMonth()->toDateString();
        $dateTo = $filters['date_to'] ?? Carbon::now()->endOfMonth()->toDateString();
        $accountId = isset($filters['account_id']) ? (int) $filters['account_id'] : null;

        $accounts = FinancialAccount::where('company_id', $company->getKey())
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $report = $this->reports->generalLedger($company, $dateFrom, $dateTo, $accountId);

        return view('reports.general-ledger-print', [
            'report' => $report,
            'accounts' => $accounts,
            'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo, 'account_id' => $accountId],
        ]);
    }

    public function printIncomeStatement(Request $request): View|RedirectResponse
    {
        $company = app(CompanyContextService::class)->requireCurrent();

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'comparison_from' => ['nullable', 'date', 'required_with:comparison_to'],
            'comparison_to' => ['nullable', 'date', 'after_or_equal:comparison_from', 'required_with:comparison_from'],
        ]);

        $dateFrom = $filters['date_from'] ?? Carbon::now()->startOfMonth()->toDateString();
        $dateTo = $filters['date_to'] ?? Carbon::now()->endOfMonth()->toDateString();
        $comparisonFrom = $filters['comparison_from'] ?? null;
        $comparisonTo = $filters['comparison_to'] ?? null;

        $report = $this->reports->incomeStatement($company, $dateFrom, $dateTo, $comparisonFrom, $comparisonTo);

        return view('reports.income-statement-print', [
            'report' => $report,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'comparison_from' => $comparisonFrom,
                'comparison_to' => $comparisonTo,
            ],
        ]);
    }

    public function printBalanceSheet(Request $request): View|RedirectResponse
    {
        $company = app(CompanyContextService::class)->requireCurrent();

        $filters = $request->validate([
            'as_of_date' => ['nullable', 'date'],
            'period_start' => ['nullable', 'date', 'before_or_equal:as_of_date'],
        ]);

        $asOfDate = $filters['as_of_date'] ?? Carbon::now()->toDateString();
        $periodStart = $filters['period_start'] ?? null;

        $report = $this->reports->balanceSheet($company, $asOfDate, $periodStart);

        return view('reports.balance-sheet-print', [
            'report' => $report,
            'filters' => ['as_of_date' => $asOfDate, 'period_start' => $periodStart],
        ]);
    }
}
