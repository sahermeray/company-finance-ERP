<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LocalePreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'locale' => ['required', Rule::in(['ar', 'en'])],
            'return_to' => [
                'nullable',
                'string',
                'max:2048',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (
                        ! is_string($value)
                        || ! str_starts_with($value, '/')
                        || str_starts_with($value, '//')
                        || str_contains($value, '\\')
                    ) {
                        $fail(__('ui.validation.invalid_return_path'));
                    }
                },
            ],
        ];
    }
}
