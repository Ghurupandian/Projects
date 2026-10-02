<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'name' => fake()->words(2, true).' Plan',
            'billing_cycle' => 'monthly',
            'base_price_paise' => fake()->numberBetween(50000, 500000), // ₹500 to ₹5,000
            'included_units' => fake()->numberBetween(1000, 10000),
            'overage_rate_paise' => fake()->numberBetween(10, 100), // 10 paise to 100 paise
            'is_active' => true,
        ];
    }
}
