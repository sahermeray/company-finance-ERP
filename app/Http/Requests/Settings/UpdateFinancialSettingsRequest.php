<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFinancialSettingsRequest extends FormRequest
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
            'base_currency'  => ['required', 'string', Rule::in(['SYP', 'USD'])],
            'confirm_change' => ['required', 'accepted'],
        ];
    }
}
