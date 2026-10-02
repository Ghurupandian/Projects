<?php

namespace Database\Factories;

use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Merchant>
 */
class MerchantFactory extends Factory
{
    protected $model = Merchant::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plainKey = 'sk_test_'.Str::random(32);

        return [
            'name' => fake()->company(),
            'api_key_hash' => Merchant::hashApiKey($plainKey),
        ];
    }

    /**
     * State to assign a known plain API key.
     */
    public function withApiKey(string $plainKey): static
    {
        return $this->state(fn (array $attributes) => [
            'api_key_hash' => Merchant::hashApiKey($plainKey),
        ]);
    }
}
