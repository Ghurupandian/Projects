<?php

namespace Tests\Feature;

use App\Actions\CreateSubscriptionAction;
use App\Actions\GetMerchantDashboardAction;
use App\Models\Customer;
use App\Models\DailyUsage;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\SubscriptionSegment;
use Carbon\Carbon;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MerchantDashboardTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-02 12:00:00');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    public function test_rejects_dashboard_access_for_another_merchant(): void
    {
        $authenticated = Merchant::factory()->withApiKey('dashboard-own-key')->create();
        $otherMerchant = Merchant::factory()->create();

        $this->getJson("/api/merchants/{$otherMerchant->id}/dashboard", [
            'X-API-Key' => 'dashboard-own-key',
        ])->assertForbidden()
            ->assertJsonPath('error', 'Forbidden');

        $this->assertNotSame($authenticated->id, $otherMerchant->id);
    }

    public function test_dashboard_returns_top_five_and_churn_from_demo_data(): void
    {
        app(DemoDataSeeder::class)->run();
        $merchant = Merchant::query()->where('name', 'Acme Cloud Corp')->sole();

        $dashboard = app(GetMerchantDashboardAction::class)->execute($merchant->id)->data;

        $this->assertSame(
            [
                'demo-customer-004',
                'demo-customer-003',
                'demo-customer-002',
                'demo-customer-001',
            ],
            array_column($dashboard['top_customers'], 'customer_reference'),
        );
        $this->assertSame(5000, $dashboard['top_customers'][0]['usage_units']);
        $this->assertSame(50000, $dashboard['top_customers'][0]['included_units']);
        $this->assertSame(10.0, $dashboard['top_customers'][0]['allowance_percent']);
        $this->assertEqualsCanonicalizing(
            ['demo-customer-001', 'demo-customer-002'],
            array_column($dashboard['churn_risk_customers'], 'customer_reference'),
        );
    }

    public function test_dashboard_never_includes_another_merchants_usage(): void
    {
        [$merchant, $customer] = $this->customerWithSubscription('dashboard-isolated-key', 'tenant-a');
        [$otherMerchant, $otherCustomer] = $this->customerWithSubscription('dashboard-other-key', 'tenant-b');
        $this->dailyUsage($merchant, $customer, '2026-10-02', 12);
        $this->dailyUsage($otherMerchant, $otherCustomer, '2026-10-02', 9999);

        $response = $this->getJson("/api/merchants/{$merchant->id}/dashboard", [
            'X-API-Key' => 'dashboard-isolated-key',
        ])->assertOk();

        $references = array_column($response->json('top_customers'), 'customer_reference');
        $this->assertContains($customer->customer_reference, $references);
        $this->assertNotContains($otherCustomer->customer_reference, $references);
        $this->assertSame(12, $response->json('current_cycle_usage.used_units'));
    }

    public function test_dashboard_cache_miss_uses_at_most_five_dashboard_queries_and_auth_lookup(): void
    {
        [$merchant, $customer] = $this->customerWithSubscription('dashboard-query-key', 'query-test');
        $this->dailyUsage($merchant, $customer, '2026-10-02', 10);
        $selectQueries = [];

        DB::listen(function ($query) use (&$selectQueries): void {
            if (preg_match('/^\s*select\b/i', $query->sql) === 1) {
                $selectQueries[] = $query->sql;
            }
        });

        $this->getJson("/api/merchants/{$merchant->id}/dashboard", [
            'X-API-Key' => 'dashboard-query-key',
        ])->assertOk();

        $this->assertLessThanOrEqual(6, count($selectQueries), implode("\n", $selectQueries));
    }

    public function test_day_one_has_no_churn_or_projected_overage(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');
        Cache::flush();
        [$merchant] = $this->customerWithSubscription('dashboard-day-one-key', 'day-one');

        $response = $this->getJson("/api/merchants/{$merchant->id}/dashboard", [
            'X-API-Key' => 'dashboard-day-one-key',
        ])->assertOk();

        $this->assertSame([], $response->json('churn_risk_customers'));
        $this->assertSame(0, $response->json('projected_overage_revenue_paise'));
    }

    public function test_projection_uses_actual_usage_for_segment_ended_mid_month(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        Cache::flush();
        [$merchant, $customer, $subscription, $oldPlan] = $this->customerWithSubscription(
            'dashboard-segment-key',
            'segment-ended',
            100,
            1,
        );
        $newPlan = $this->plan($merchant, 'New segment plan', 1000, 0);
        SubscriptionSegment::query()->where('subscription_id', $subscription->id)->firstOrFail()
            ->update(['ends_at' => '2026-10-15']);
        $subscription->segments()->create([
            'plan_id' => $newPlan->id,
            'starts_at' => '2026-10-16',
            'ends_at' => null,
        ]);
        $subscription->update(['current_plan_id' => $newPlan->id]);

        for ($day = 1; $day <= 19; $day++) {
            $this->dailyUsage($merchant, $customer, sprintf('2026-10-%02d', $day), 10);
        }

        $dashboard = app(GetMerchantDashboardAction::class)->execute($merchant->id)->data;

        $this->assertSame(190, $dashboard['current_cycle_usage']['used_units']);
        $this->assertSame(102, $dashboard['projected_overage_revenue_paise']);
    }

    public function test_empty_merchant_gets_empty_customer_lists_and_zero_usage(): void
    {
        $merchant = Merchant::factory()->withApiKey('dashboard-empty-key')->create();

        $response = $this->getJson("/api/merchants/{$merchant->id}/dashboard", [
            'X-API-Key' => 'dashboard-empty-key',
        ])->assertOk();

        $this->assertSame([], $response->json('top_customers'));
        $this->assertSame([], $response->json('churn_risk_customers'));
        $this->assertSame(0, $response->json('current_cycle_usage.used_units'));
        $this->assertCount(30, $response->json('daily_usage_trend'));
    }

    private function customerWithSubscription(
        string $apiKey,
        string $reference,
        int $includedUnits = 1000,
        int $overageRate = 10,
    ): array {
        $merchant = Merchant::factory()->withApiKey($apiKey)->create();
        $customer = Customer::factory()->create([
            'merchant_id' => $merchant->id,
            'customer_reference' => $reference.'-'.uniqid(),
        ]);
        $plan = $this->plan($merchant, 'Dashboard plan', $includedUnits, $overageRate);
        $subscription = app(CreateSubscriptionAction::class)->execute(
            $customer->id,
            $plan->id,
            '2026-09-01',
        );

        return [$merchant, $customer, $subscription, $plan];
    }

    private function plan(Merchant $merchant, string $name, int $includedUnits, int $overageRate): Plan
    {
        return Plan::query()->create([
            'merchant_id' => $merchant->id,
            'name' => $name.'-'.uniqid(),
            'billing_cycle' => 'monthly',
            'base_price_paise' => 10000,
            'included_units' => $includedUnits,
            'overage_rate_paise' => $overageRate,
            'is_active' => true,
        ]);
    }

    private function dailyUsage(Merchant $merchant, Customer $customer, string $date, int $units): void
    {
        DailyUsage::query()->create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'usage_date' => $date,
            'total_units' => $units,
        ]);
    }
}
