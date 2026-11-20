<?php

namespace App\Http\Requests\Settings;

use App\Services\CompanyContextService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string|\Illuminate\Validation\Rules\In>>
     */
    public function rules(): array
    {
        return [
            'currency'     => ['required', 'string', Rule::in($this->foreignCurrencies())],
            'rate'         => ['required', 'numeric', 'gt:0', 'decimal:0,10'],
            'effective_at' => ['required', 'date', 'before_or_equal:'.now()->addYear()->toDateString()],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function foreignCurrencies(): array
    {
        $company = app(CompanyContextService::class)->requireCurrent();
        $base = strtoupper((string) $company->base_currency);

        return array_values(array_filter(['SYP', 'USD'], fn (string $c) => $c !== $base));
    }
}
