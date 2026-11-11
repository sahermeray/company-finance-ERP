<?php

namespace Database\Factories;

use App\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => null,
            'currency' => 'USD',
            'base_currency' => 'SYP',
            'rate' => '12000.0000000000',
            'effective_at' => now(),
            'source' => 'manual',
            'created_by' => null,
        ];
    }
}
