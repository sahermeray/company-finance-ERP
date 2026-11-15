<?php

namespace App\Http\Controllers;

use App\Models\CustomerReturn;
use App\Models\Party;
use App\Models\PartyType;
use App\Models\Purchase;
use App\Models\Revenue;
use App\Models\SupplierReturn;
use App\Services\CompanyContextService;
use App\Services\PartyStatementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PartyController extends Controller
{
    public function customers(Request $request): View
    {
        return $this->index($request, 'customer');
    }

    public function suppliers(Request $request): View
    {
        return $this->index($request, 'supplier');
    }

    public function createCustomer(): View
    {
        return $this->create('customer');
    }

    public function createSupplier(): View
    {
        return $this->create('supplier');
    }

    public function storeCustomer(Request $request): RedirectResponse
    {
        return $this->store($request, 'customer');
    }

    public function storeSupplier(Request $request): RedirectResponse
    {
        return $this->store($request, 'supplier');
    }

    public function showCustomer(Party $party): View
    {
        return $this->show($party, 'customer');
    }

    public function showSupplier(Party $party): View
    {
        return $this->show($party, 'supplier');
    }

    public function editCustomer(Party $party): View
    {
        return $this->edit($party, 'customer');
    }

    public function editSupplier(Party $party): View
    {
        return $this->edit($party, 'supplier');
    }

    public function updateCustomer(Request $request, Party $party): RedirectResponse
    {
        return $this->update($request, $party, 'customer');
    }

    public function updateSupplier(Request $request, Party $party): RedirectResponse
    {
        return $this->update($request, $party, 'supplier');
    }

    public function customerStatement(Request $request, Party $party, PartyStatementService $statements): View
    {
        return $this->statement($request, $party, $statements, 'customer');
    }

    public function supplierStatement(Request $request, Party $party, PartyStatementService $statements): View
    {
        return $this->statement($request, $party, $statements, 'supplier');
    }

    public function printCustomerStatement(Request $request, Party $party, PartyStatementService $statements): View
    {
        return $this->printStatement($request, $party, $statements, 'customer');
    }

    public function printSupplierStatement(Request $request, Party $party, PartyStatementService $statements): View
    {
        return $this->printStatement($request, $party, $statements, 'supplier');
    }

    private function index(Request $request, string $kind): View
    {
        $this->authorize('viewAny', Party::class);
        $companyId = (int) app(CompanyContextService::class)->requireCurrent()->getKey();
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $typeCode = $kind;
        $historyRelation = $kind === 'customer' ? 'revenues' : 'purchases';

        $parties = Party::query()
            ->where('company_id', $companyId)
            ->where(function (Builder $query) use ($typeCode, $historyRelation): void {
                $query->whereHas('partyTypes', fn (Builder $types) => $types->where('code', $typeCode))
                    ->orWhereHas($historyRelation);
            })
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%')
                    ->orWhere('code', 'like', '%'.$search.'%')
                    ->orWhere('legal_name', 'like', '%'.$search.'%')
                    ->orWhere('tax_identifier', 'like', '%'.$search.'%');
            }))
            ->withCount($historyRelation)
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('commercial.parties.index', compact('kind', 'parties', 'filters'));
    }

    private function create(string $kind): View
    {
        $this->authorize('viewAny', Party::class);

        return view('commercial.parties.form', ['kind' => $kind, 'party' => new Party]);
    }

    private function store(Request $request, string $kind): RedirectResponse
    {
        $this->authorize('viewAny', Party::class);
        $company = app(CompanyContextService::class)->requireCurrent();
        $data = $this->validatedParty($request, (int) $company->getKey());
        $party = Party::query()->create([...$data, 'company_id' => $company->getKey()]);
        $party->partyTypes()->syncWithoutDetaching([$this->partyType($kind, (int) $company->getKey())->getKey()]);

        return redirect()->route($kind.'s.show', $party)->with('status', __('ui.parties.saved'));
    }

    private function show(Party $party, string $kind): View
    {
        $this->authorize('view', $party);
        $companyId = (int) app(CompanyContextService::class)->requireCurrent()->getKey();
        $statement = app(PartyStatementService::class)->statement($party, $kind);
        $history = $kind === 'customer'
            ? Revenue::query()->where('company_id', $companyId)->where('party_id', $party->getKey())->orderByDesc('revenue_date')->orderByDesc('id')->limit(10)->get()
            : Purchase::query()->where('company_id', $companyId)->where('party_id', $party->getKey())->orderByDesc('purchase_date')->orderByDesc('id')->limit(10)->get();
        $returns = $kind === 'customer'
            ? CustomerReturn::query()->where('company_id', $companyId)->where('party_id', $party->getKey())->orderByDesc('return_date')->orderByDesc('id')->limit(10)->get()
            : SupplierReturn::query()->where('company_id', $companyId)->where('party_id', $party->getKey())->orderByDesc('return_date')->orderByDesc('id')->limit(10)->get();

        return view('commercial.parties.show', compact('kind', 'party', 'statement', 'history', 'returns'));
    }

    private function edit(Party $party, string $kind): View
    {
        $this->authorize('view', $party);

        return view('commercial.parties.form', compact('kind', 'party'));
    }

    private function update(Request $request, Party $party, string $kind): RedirectResponse
    {
        $this->authorize('view', $party);
        $companyId = (int) app(CompanyContextService::class)->requireCurrent()->getKey();
        $data = $this->validatedParty($request, $companyId, $party);
        $party->update($data);
        $party->partyTypes()->syncWithoutDetaching([$this->partyType($kind, $companyId)->getKey()]);

        return redirect()->route($kind.'s.show', $party)->with('status', __('ui.parties.saved'));
    }

    private function statement(Request $request, Party $party, PartyStatementService $statements, string $kind): View
    {
        $this->authorize('view', $party);
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $statement = $statements->statement($party, $kind, $filters['date_from'] ?? null, $filters['date_to'] ?? null);

        return view('commercial.parties.statement', compact('kind', 'party', 'statement', 'filters'));
    }

    private function printStatement(Request $request, Party $party, PartyStatementService $statements, string $kind): View
    {
        $this->authorize('view', $party);
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $statement = $statements->statement($party, $kind, $filters['date_from'] ?? null, $filters['date_to'] ?? null);

        return view('commercial.parties.statement-print', compact('kind', 'party', 'statement', 'filters'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedParty(Request $request, int $companyId, ?Party $party = null): array
    {
        $codeRule = Rule::unique('parties', 'code')->where('company_id', $companyId);
        if ($party !== null) {
            $codeRule->ignore($party->getKey());
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255', $codeRule],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'tax_identifier' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);
    }

    private function partyType(string $kind, int $companyId): PartyType
    {
        return PartyType::query()->firstOrCreate(
            ['company_id' => $companyId, 'code' => $kind],
            ['name' => __('ui.parties.'.$kind), 'is_active' => true],
        );
    }
}
