<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * Define the model's default state.
     *
     * Returns an active subscription beginning on the first day of the current month.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $merchant = Merchant::factory()->create();
        $plan = Plan::factory()->create(['merchant_id' => $merchant->id]);

        return [
            'merchant_id' => $merchant->id,
            'customer_id' => Customer::factory()->create(['merchant_id' => $merchant->id])->id,
            'current_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => Carbon::now()->startOfMonth()->toDateString(),
            'ends_at' => null,
        ];
    }

    /**
     * State for a subscription starting on a specific date.
     */
    public function startingOn(string $date): static
    {
        return $this->state(fn (array $attributes) => ['starts_at' => $date]);
    }

    /**
     * State for a subscription ending on a specific date.
     */
    public function endingOn(string $date): static
    {
        return $this->state(fn (array $attributes) => ['ends_at' => $date]);
    }
}
