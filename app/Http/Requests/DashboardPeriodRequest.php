<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DashboardPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('period')) {
            $this->merge(['period' => 'month']);
        }
    }

    public function rules(): array
    {
        return [
            'period' => ['required', Rule::in(['today', 'week', 'month', 'year', 'custom'])],
            'start_date' => [
                'exclude_unless:period,custom',
                'required',
                'date_format:Y-m-d',
            ],
            'end_date' => [
                'exclude_unless:period,custom',
                'required',
                'date_format:Y-m-d',
                'after_or_equal:start_date',
            ],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    $this->input('period') !== 'custom'
                    || $validator->errors()->hasAny(['start_date', 'end_date'])
                ) {
                    return;
                }

                $periodDays = CarbonImmutable::parse($this->string('start_date')->toString())
                    ->diffInDays(CarbonImmutable::parse($this->string('end_date')->toString())) + 1;

                if ($periodDays > 366) {
                    $validator->errors()->add('end_date', __('ui.validation.period_too_long'));
                }
            },
        ];
    }
}
