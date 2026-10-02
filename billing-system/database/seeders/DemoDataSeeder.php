<?php

namespace Database\Seeders;

use App\Actions\ChangePlanAction;
use App\Actions\CreateSubscriptionAction;
use App\Actions\GenerateSubscriptionInvoiceAction;
use App\Actions\RecomputeCycleDailyUsageAction;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionSegment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $plainApiKey = 'sk_live_'.Str::random(32);
        $merchant = Merchant::query()->firstOrCreate(
            ['name' => 'Acme Cloud Corp'],
            ['api_key_hash' => Merchant::hashApiKey($plainApiKey)],
        );

        $starter = $this->plan($merchant, 'Starter', 100000, 10000, 15);
        $pro = $this->plan($merchant, 'Pro', 300000, 50000, 10);
        $growth = $this->plan($merchant, 'Growth', 500000, 100000, 5);

        $definitions = [
            ['reference' => 'demo-customer-001', 'name' => 'Asha Sharma', 'plan' => $starter, 'sept' => 900, 'oct' => 100],
            ['reference' => 'demo-customer-002', 'name' => 'Ravi Patel', 'plan' => $pro, 'sept' => 1200, 'oct' => 200],
            ['reference' => 'demo-customer-003', 'name' => 'Mira Iyer', 'plan' => $starter, 'sept' => 400, 'oct' => 600, 'change' => true],
            ['reference' => 'demo-customer-004', 'name' => 'Dev Mehta', 'plan' => $pro, 'sept' => 3000, 'oct' => 2500],
        ];

        $subscriptions = [];
        $usageRows = [];

        foreach ($definitions as $definition) {
            $customer = Customer::query()->updateOrCreate(
                [
                    'merchant_id' => $merchant->id,
                    'customer_reference' => $definition['reference'],
                ],
                [
                    'name' => $definition['name'],
                    'email' => str_replace('-', '.', $definition['reference']).'@example.test',
                ],
            );

            $subscription = Subscription::query()
                ->where('customer_id', $customer->id)
                ->whereDate('starts_at', '2026-09-01')
                ->first();

            if ($subscription === null) {
                $subscription = app(CreateSubscriptionAction::class)->execute(
                    $customer->id,
                    $definition['plan']->id,
                    '2026-09-01',
                );
            }

            $subscriptions[] = $subscription;

            if (($definition['change'] ?? false) && $subscription->current_plan_id !== $growth->id) {
                $openSegment = SubscriptionSegment::query()
                    ->where('subscription_id', $subscription->id)
                    ->whereNull('ends_at')
                    ->firstOrFail();

                if ($openSegment->plan_id !== $growth->id) {
                    app(ChangePlanAction::class)->execute($subscription->id, $growth->id, '2026-10-02');
                }
            }

            for ($day = 1; $day <= 30; $day++) {
                $usageRows[] = $this->usageRow(
                    (int) $merchant->id,
                    (int) $customer->id,
                    $definition['reference'],
                    sprintf('2026-09-%02d', $day),
                    $definition['sept'],
                );
            }

            foreach ([1, 2] as $day) {
                $units = $definition['oct'];

                if (($definition['change'] ?? false) && $day === 1) {
                    $units = $definition['sept'];
                }

                $usageRows[] = $this->usageRow(
                    (int) $merchant->id,
                    (int) $customer->id,
                    $definition['reference'],
                    sprintf('2026-10-%02d', $day),
                    $units,
                );
            }
        }

        foreach (array_chunk($usageRows, 500) as $chunk) {
            DB::table('usage_events')->insertOrIgnore($chunk);
        }

        $customerIds = array_map(
            static fn (Subscription $subscription): int => (int) $subscription->customer_id,
            $subscriptions,
        );

        app(RecomputeCycleDailyUsageAction::class)->execute('2026-09-01', '2026-09-30', $customerIds);

        foreach ($subscriptions as $subscription) {
            app(GenerateSubscriptionInvoiceAction::class)->execute(
                (int) $subscription->id,
                '2026-09-01',
                '2026-09-30',
            );
        }

        app(RecomputeCycleDailyUsageAction::class)->execute('2026-10-01', '2026-10-31', $customerIds);

        if ($merchant->wasRecentlyCreated) {
            $this->command?->warn("Demo API Key: {$plainApiKey}");
        } else {
            $this->command?->warn('Demo merchant already existed; its original API key is not available for display.');
        }

        $this->command?->info('Demo customers, September/October usage, and September invoices are ready.');
    }

    private function plan(Merchant $merchant, string $name, int $basePaise, int $includedUnits, int $ratePaise): Plan
    {
        return Plan::query()->firstOrCreate(
            ['merchant_id' => $merchant->id, 'name' => $name],
            [
                'billing_cycle' => 'monthly',
                'base_price_paise' => $basePaise,
                'included_units' => $includedUnits,
                'overage_rate_paise' => $ratePaise,
                'is_active' => true,
            ],
        );
    }

    /**
     * @return array<string, int|string>
     */
    private function usageRow(int $merchantId, int $customerId, string $reference, string $date, int $units): array
    {
        return [
            'merchant_id' => $merchantId,
            'customer_id' => $customerId,
            'idempotency_key' => 'demo-'.$reference.'-'.$date,
            'units' => $units,
            'occurred_at' => $date.' 12:00:00',
            'created_at' => $date.' 12:00:00',
        ];
    }
}
