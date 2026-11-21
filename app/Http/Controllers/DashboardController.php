<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardPeriodRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\BusinessInsightsService;
use App\Services\CompanyContextService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(
        DashboardPeriodRequest $request,
        BusinessInsightsService $businessInsights,
        CompanyContextService $companyContext,
    ): View|RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $companies = $user->companies()
            ->orderBy('name')
            ->orderBy('companies.id')
            ->get(['companies.id', 'companies.name', 'companies.base_currency']);
        $company = $companyContext->current();

        if ($company === null) {
            if ($companies->isEmpty()) {
                abort(403, __('ui.companies.no_access'));
            }

            if ($companies->count() > 1) {
                return redirect()->route('companies.select');
            }

            $company = $companyContext->switchTo($user, (int) $companies->first()->getKey());
        }

        // Enforce dashboard.view permission after the company context is resolved.
        app(\App\Services\CompanyAuthorizationService::class)->requirePermission($user, $company, 'dashboard.view');

        [$fromDate, $toDate] = $this->periodDates($request, $company);
        $timezone = $company->timezone ?: config('app.timezone');
        $isCurrentSnapshot = $toDate === CarbonImmutable::now($timezone)->toDateString();
        $insights = $businessInsights->insights($company, $fromDate, $toDate);
        $metrics = $this->dashboardMetrics($insights, $isCurrentSnapshot);
        $trend = $insights['financial_performance']['trend'];
        $chartMonths = $this->chartMonths($trend);

        return view('dashboard.index', [
            'company' => $company,
            'companies' => $companies,
            'metrics' => $metrics,
            'period' => $request->validated('period'),
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'warnings' => $this->dashboardWarnings($insights, $isCurrentSnapshot),
            'chartMonths' => $chartMonths,
            'chartAvailable' => $trend['status'] === 'complete' && $chartMonths !== [],
            'trendStatus' => $trend['status'],
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function periodDates(DashboardPeriodRequest $request, Company $company): array
    {
        $timezone = $company->timezone ?: config('app.timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();

        return match ($request->validated('period')) {
            'today' => [$today->toDateString(), $today->toDateString()],
            'week' => [$today->startOfWeek(Carbon::MONDAY)->toDateString(), $today->toDateString()],
            'year' => [$today->startOfYear()->toDateString(), $today->toDateString()],
            'custom' => [
                $request->validated('start_date'),
                $request->validated('end_date'),
            ],
            default => [$today->startOfMonth()->toDateString(), $today->toDateString()],
        };
    }

    /**
     * @param  array<string, mixed>  $insights
     * @return array<string, array<string, mixed>>
     */
    private function dashboardMetrics(array $insights, bool $isCurrentSnapshot): array
    {
        $financialMetrics = $insights['financial_performance']['metrics'];
        $subledgers = $insights['receivables_and_payables'];

        return [
            'sales_revenue' => $financialMetrics['revenue'],
            'purchases' => $insights['sales_and_purchases']['purchases_total'],
            'gross_profit' => $financialMetrics['gross_profit'],
            'operating_expenses' => $financialMetrics['operating_expenses'],
            'net_profit' => $financialMetrics['net_profit'],
            'inventory' => $this->snapshotMetric(
                $insights['inventory']['total_valued_inventory'],
                $isCurrentSnapshot,
            ),
            'receivables' => $this->subledgerMetric($subledgers['customer_receivables'], $isCurrentSnapshot),
            'payables' => $this->subledgerMetric($subledgers['supplier_payables'], $isCurrentSnapshot),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function subledgerMetric(array $report, bool $isCurrentSnapshot): array
    {
        $value = $report['operational_balance'];
        $supported = $report['supported'];
        $status = $report['status'];

        return $this->snapshotMetric([
            'value' => $value,
            'availability' => ! $supported
                ? 'unsupported'
                : ($value === null ? 'unavailable' : ($status === 'consistent' ? 'available' : 'incomplete')),
            'currency' => $report['currency'],
            'status' => $status,
        ], $isCurrentSnapshot);
    }

    /**
     * @param  array<string, mixed>  $metric
     * @return array<string, mixed>
     */
    private function snapshotMetric(array $metric, bool $isCurrentSnapshot): array
    {
        if ($isCurrentSnapshot) {
            return $metric;
        }

        return [
            ...$metric,
            'value' => null,
            'availability' => 'unsupported',
            'unavailable_description' => __('ui.status.snapshot_period_unsupported'),
        ];
    }

    /**
     * @param  array<string, mixed>  $trend
     * @return array<int, array<string, int|string>>
     */
    private function chartMonths(array $trend): array
    {
        $months = [];
        $maximum = 0.0;

        foreach ($trend['months'] as $month => $values) {
            $months[] = [
                'month' => $month,
                'revenue' => $values['revenue'],
                'expenses' => $values['operating_expense'],
                'profit' => $values['net_profit'],
            ];

            foreach (['revenue', 'operating_expense', 'net_profit'] as $metric) {
                $maximum = max($maximum, abs((float) $values[$metric]));
            }
        }

        $scale = max(1.0, $maximum);
        foreach ($months as &$month) {
            foreach (['revenue', 'expenses', 'profit'] as $metric) {
                $month[$metric.'_width'] = (int) round(abs((float) $month[$metric]) / $scale * 100);
            }
        }
        unset($month);

        return $months;
    }

    /**
     * @param  array<string, mixed>  $insights
     * @return array<int, array{type: string, category: string, message: string}>
     */
    private function dashboardWarnings(array $insights, bool $isCurrentSnapshot): array
    {
        $warnings = [];

        foreach ($insights['warnings'] as $warning) {
            if (
                ! $isCurrentSnapshot
                && in_array($warning['category'] ?? null, ['customer_receivables', 'supplier_payables', 'inventory'], true)
            ) {
                continue;
            }

            $discrepancies = $warning['discrepancies'] ?? [];
            $isDiscrepancy = in_array($warning['status'] ?? null, ['discrepant', 'inconsistent'], true);
            $category = match ($warning['category'] ?? null) {
                'inventory' => __('ui.warnings.inventory'),
                'customer_receivables' => __('ui.warnings.receivables'),
                'supplier_payables' => __('ui.warnings.payables'),
                'source_documents' => __('ui.warnings.business_documents'),
                default => __('ui.warnings.financial_data'),
            };

            $warnings[] = [
                'type' => $isDiscrepancy ? 'discrepancy' : 'incomplete',
                'category' => $category,
                'message' => $isDiscrepancy
                    ? __('ui.warnings.discrepancy_found', ['count' => max(1, count($discrepancies))])
                    : __('ui.warnings.incomplete_data'),
            ];
        }

        $unvaluedProducts = $isCurrentSnapshot
            ? ($insights['inventory']['unvalued_legacy_inventory'] ?? [])
            : [];
        if ($unvaluedProducts !== []) {
            $warnings[] = [
                'type' => 'incomplete',
                'category' => __('ui.warnings.inventory'),
                'message' => __('ui.warnings.unvalued_inventory', ['count' => count($unvaluedProducts)]),
            ];
        }

        return $warnings;
    }
}
