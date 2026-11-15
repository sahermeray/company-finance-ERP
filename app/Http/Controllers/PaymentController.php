<?php

namespace App\Http\Controllers;

use App\Models\FinancialAccount;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Revenue;
use App\Services\CompanyContextService;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Payment::class);
        $company = app(CompanyContextService::class)->requireCurrent();
        $currencies = array_values(array_unique([strtoupper((string) $company->base_currency), 'SYP', 'USD']));
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'party_id' => ['nullable', 'integer', Rule::exists('parties', 'id')->where('company_id', $company->getKey())],
            'direction' => ['nullable', Rule::in(['inbound', 'outbound'])],
            'currency' => ['nullable', Rule::in($currencies)],
            'status' => ['nullable', Rule::in(['draft', 'posted', 'reversed'])],
        ]);

        $payments = Payment::query()
            ->with(['party', 'payable', 'financialAccount', 'reversalOf', 'reversedBy'])
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('paid_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('paid_at', '<=', $date))
            ->when($filters['party_id'] ?? null, fn (Builder $query, int $partyId) => $query->where('party_id', $partyId))
            ->when($filters['direction'] ?? null, fn (Builder $query, string $direction) => $query->where('direction', strtoupper($direction)))
            ->when($filters['currency'] ?? null, fn (Builder $query, string $currency) => $query->where('currency', $currency))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('commercial.payments.index', [
            'payments' => $payments,
            'parties' => Party::query()->orderBy('name')->get(),
            'filters' => $filters,
            'currencies' => $currencies,
        ]);
    }

    public function create(PaymentService $paymentService): View
    {
        $this->authorize('viewAny', Payment::class);
        $company = app(CompanyContextService::class)->requireCurrent();
        $companyId = (int) $company->getKey();
        $customers = Revenue::query()->with('party')->where('company_id', $companyId)->where('status', 'posted')->orderByDesc('revenue_date')->get()
            ->map(fn (Revenue $invoice): array => ['invoice' => $invoice, 'outstanding' => $paymentService->calculateOutstandingForRevenue($invoice)])
            ->filter(fn (array $row): bool => bccomp($row['outstanding'], '0', 4) > 0)->values();
        $suppliers = Purchase::query()->with('party')->where('company_id', $companyId)->where('status', 'posted')->orderByDesc('purchase_date')->get()
            ->map(fn (Purchase $invoice): array => ['invoice' => $invoice, 'outstanding' => $paymentService->calculateOutstandingForPurchase($invoice)])
            ->filter(fn (array $row): bool => bccomp($row['outstanding'], '0', 4) > 0)->values();

        return view('commercial.payments.create', [
            'customers' => $customers,
            'suppliers' => $suppliers,
            'accounts' => FinancialAccount::query()->where('company_id', $companyId)->where('is_active', true)->where('account_type', 'asset')->orderBy('currency')->orderBy('code')->get(),
            'currencies' => array_values(array_unique([strtoupper((string) $company->base_currency), 'SYP', 'USD'])),
        ]);
    }

    public function store(Request $request, PaymentService $paymentService): RedirectResponse
    {
        $this->authorize('viewAny', Payment::class);
        $company = app(CompanyContextService::class)->requireCurrent();
        $companyId = (int) $company->getKey();
        $data = $request->validate([
            'direction' => ['required', Rule::in(['inbound', 'outbound'])],
            'payable_id' => ['required', 'integer'],
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')->where('company_id', $companyId)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999.9999'],
            'currency' => ['required', Rule::in(array_values(array_unique([strtoupper((string) $company->base_currency), 'SYP', 'USD'])))],
            'financial_account_id' => ['required', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $companyId)->where('account_type', 'asset')->where('is_active', true)],
            'paid_at' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $documentClass = $data['direction'] === 'inbound' ? Revenue::class : Purchase::class;
        $document = $documentClass::query()
            ->where('company_id', $companyId)
            ->where('party_id', $data['party_id'])
            ->where('status', 'posted')
            ->findOrFail($data['payable_id']);
        $financialAccount = FinancialAccount::query()->findOrFail($data['financial_account_id']);

        if (strtoupper((string) $financialAccount->currency) !== strtoupper($data['currency'])) {
            throw ValidationException::withMessages(['currency' => __('ui.payments.account_currency_mismatch')]);
        }

        $before = $data['direction'] === 'inbound'
            ? $paymentService->calculateOutstandingForRevenue($document)
            : $paymentService->calculateOutstandingForPurchase($document);

        if (bccomp($before, '0', 4) <= 0) {
            throw ValidationException::withMessages(['payable_id' => __('ui.payments.invoice_settled')]);
        }

        $attributes = [
            'party_id' => $data['party_id'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'financial_account_id' => $data['financial_account_id'],
            'paid_at' => $data['paid_at'],
            'reference' => $data['reference'] ?? null,
        ];

        if (strtoupper($data['currency']) === strtoupper((string) $document->currency)) {
            $attributes['exchange_rate'] = $document->exchange_rate;
        }

        if ($document instanceof Revenue && $document->receivable_account_id !== null) {
            $attributes['receivable_account_id'] = $document->receivable_account_id;
        }
        if ($document instanceof Purchase && $document->payable_account_id !== null) {
            $attributes['payable_account_id'] = $document->payable_account_id;
        }

        $payment = $data['direction'] === 'inbound'
            ? $paymentService->recordCustomerReceipt($document, $attributes)
            : $paymentService->recordSupplierPayment($document, $attributes);
        $after = $data['direction'] === 'inbound'
            ? $paymentService->calculateOutstandingForRevenue($document->fresh())
            : $paymentService->calculateOutstandingForPurchase($document->fresh());

        return redirect()->route('payments.show', $payment)
            ->with('status', __('ui.payments.saved'))
            ->with('settlement', ['before' => $before, 'after' => $after, 'currency' => $document->currency]);
    }

    public function show(Payment $payment): View
    {
        $this->authorize('view', $payment);
        $payment->load(['party', 'payable', 'financialAccount', 'reversalOf', 'reversedBy']);

        return view('commercial.payments.show', compact('payment'));
    }

    public function reverse(Payment $payment, PaymentService $paymentService): RedirectResponse
    {
        $this->authorize('view', $payment);
        $company = app(CompanyContextService::class)->requireCurrent();
        $reversal = $paymentService->reversePayment($payment, [], $company);

        return redirect()->route('payments.show', $payment)->with('status', __('ui.payments.reversed', [
            'number' => $reversal->payment_number,
        ]));
    }

    public function print(Payment $payment): View
    {
        $this->authorize('view', $payment);
        $payment->load(['party', 'payable', 'financialAccount', 'reversalOf', 'reversedBy']);

        return view('commercial.payments.print', [
            'payment' => $payment,
            'company' => app(CompanyContextService::class)->requireCurrent(),
        ]);
    }
}
