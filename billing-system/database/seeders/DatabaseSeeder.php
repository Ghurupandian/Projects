<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $plainApiKey = 'sk_live_'.Str::random(32);

        $merchant = Merchant::create([
            'name' => 'Acme Cloud Corp',
            'api_key_hash' => Merchant::hashApiKey($plainApiKey),
        ]);

        $starterPlan = Plan::create([
            'merchant_id' => $merchant->id,
            'name' => 'Starter',
            'billing_cycle' => 'monthly',
            'base_price_paise' => 100000, // ₹1,000
            'included_units' => 10000,
            'overage_rate_paise' => 15, // 15 paise per unit
            'is_active' => true,
        ]);

        $proPlan = Plan::create([
            'merchant_id' => $merchant->id,
            'name' => 'Pro',
            'billing_cycle' => 'monthly',
            'base_price_paise' => 500000, // ₹5,000
            'included_units' => 50000,
            'overage_rate_paise' => 10, // 10 paise per unit
            'is_active' => true,
        ]);

        $this->command->newLine();
        $this->command->info('====================================================');
        $this->command->info(' Demo Merchant Seeded Successfully!');
        $this->command->line(" Merchant ID:   {$merchant->id}");
        $this->command->line(" Merchant Name: {$merchant->name}");
        $this->command->warn(" Plain API Key: {$plainApiKey}");
        $this->command->comment(' (Keep this key safe! It is only shown once and stored as SHA-256)');
        $this->command->line(" Plans: {$starterPlan->name} (₹".($starterPlan->base_price_paise / 100).", {$starterPlan->included_units} units, ₹".($starterPlan->overage_rate_paise / 100)."/unit)");
        $this->command->line("        {$proPlan->name} (₹".($proPlan->base_price_paise / 100).", {$proPlan->included_units} units, ₹".($proPlan->overage_rate_paise / 100)."/unit)");
        $this->command->info('====================================================');
        $this->command->newLine();
    }
}
