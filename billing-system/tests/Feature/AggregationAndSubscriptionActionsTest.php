<?php

namespace Tests\Feature;

use App\Actions\ChangePlanAction;
use App\Actions\CreateSubscriptionAction;
use App\Jobs\AggregateDailyUsageJob;
use App\Models\Customer;
use App\Models\DailyUsage;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionSegment;
use App\Models\UsageEvent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AggregationAndSubscriptionActionsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-02 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_aggregation_recomputes_daily_total_idempotently(): void
    {
        [$merchant, $customer, $plan] = $this->merchantCustomerAndPlan();
        $subscription = $this->subscription($merchant, $customer, $plan, '2026-10-01');

        $this->usage($merchant, $customer, 'batch-a', 12, '2026-10-02 01:00:00');
        $this->usage($merchant, $customer, 'batch-b', 18, '2026-10-02 08:00:00');

        (new AggregateDailyUsageJob('2026-10-02'))->handle();
        (new AggregateDailyUsageJob('2026-10-02'))->handle();

        $this->assertSame(30, (int) DailyUsage::query()
            ->where('customer_id', $customer->id)
            ->whereDate('usage_date', '2026-10-02')
            ->value('total_units'));

        $this->usage($merchant, $customer, 'batch-c', 5, '2026-10-02 09:00:00');
        (new AggregateDailyUsageJob('2026-10-02'))->handle();

        $this->assertSame(35, (int) DailyUsage::query()
            ->where('customer_id', $customer->id)
            ->whereDate('usage_date', '2026-10-02')
            ->value('total_units'));
        $this->assertSame('2026-10-01', $subscription->starts_at->toDateString());
    }

    public function test_aggregation_skips_and_logs_events_for_an_invoiced_cycle(): void
    {
        [$merchant, $customer, $plan] = $this->merchantCustomerAndPlan();
        $subscription = $this->subscription($merchant, $customer, $plan, '2026-09-01');
        $this->usage($merchant, $customer, 'late-event', 50, '2026-09-20 10:00:00');

        DB::table('invoices')->insert([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'invoice_number' => 'TEST-'.$subscription->id.'-202609',
            'cycle_start' => '2026-09-01',
            'cycle_end' => '2026-09-30',
            'base_amount_paise' => 0,
            'overage_amount_paise' => 0,
            'total_amount_paise' => 0,
            'currency' => 'INR',
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DailyUsage::query()->create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'usage_date' => '2026-09-20',
            'total_units' => 10,
        ]);

        Log::spy();
        (new AggregateDailyUsageJob('2026-09-20'))->handle();

        $this->assertSame(10, (int) DailyUsage::query()
            ->where('customer_id', $customer->id)
            ->whereDate('usage_date', '2026-09-20')
            ->value('total_units'));
        Log::shouldHaveReceived('warning')->once()->with(
            'Usage events for an already-invoiced cycle were excluded from aggregation.',
            ['usage_date' => '2026-09-20', 'event_count' => 1],
        );
    }

    public function test_create_subscription_opens_initial_segment_and_keeps_plan_in_sync(): void
    {
        [$merchant, $customer, $plan] = $this->merchantCustomerAndPlan();

        $subscription = app(CreateSubscriptionAction::class)->execute(
            $customer->id,
            $plan->id,
            '2026-10-01',
        );

        $segment = SubscriptionSegment::query()
            ->where('subscription_id', $subscription->id)
            ->sole();

        $this->assertSame($merchant->id, $subscription->merchant_id);
        $this->assertSame($plan->id, $subscription->current_plan_id);
        $this->assertSame('2026-10-01', $subscription->starts_at->toDateString());
        $this->assertSame('2026-10-01', $segment->starts_at->toDateString());
        $this->assertNull($segment->ends_at);
    }

    public function test_create_subscription_rejects_a_second_active_subscription(): void
    {
        [$merchant, $customer, $plan] = $this->merchantCustomerAndPlan();
        $this->subscription($merchant, $customer, $plan, '2026-09-01');

        $this->expectException(ValidationException::class);

        app(CreateSubscriptionAction::class)->execute($customer->id, $plan->id, '2026-10-01');
    }

    public function test_plan_change_closes_old_segment_and_syncs_current_plan(): void
    {
        [$merchant, $customer, $oldPlan] = $this->merchantCustomerAndPlan();
        $newPlan = $this->plan($merchant, 'Growth');
        $subscription = $this->subscription($merchant, $customer, $oldPlan, '2026-09-01');

        $changed = app(ChangePlanAction::class)->execute($subscription->id, $newPlan->id, '2026-10-02');

        $segments = SubscriptionSegment::query()
            ->where('subscription_id', $subscription->id)
            ->orderBy('starts_at')
            ->get();

        $this->assertSame($newPlan->id, $changed->current_plan_id);
        $this->assertCount(2, $segments);
        $this->assertSame('2026-10-01', $segments[0]->ends_at->toDateString());
        $this->assertSame('2026-10-02', $segments[1]->starts_at->toDateString());
        $this->assertNull($segments[1]->ends_at);
    }

    public function test_plan_change_rejects_effective_date_not_after_open_segment_start(): void
    {
        [$merchant, $customer, $oldPlan] = $this->merchantCustomerAndPlan();
        $newPlan = $this->plan($merchant, 'Growth');
        $subscription = $this->subscription($merchant, $customer, $oldPlan, '2026-10-02');

        $this->expectException(ValidationException::class);

        app(ChangePlanAction::class)->execute($subscription->id, $newPlan->id, '2026-10-02');
    }

    public function test_plan_change_rejects_the_current_plan(): void
    {
        [$merchant, $customer, $currentPlan] = $this->merchantCustomerAndPlan();
        $subscription = $this->subscription($merchant, $customer, $currentPlan, '2026-09-01');

        $this->expectException(ValidationException::class);

        app(ChangePlanAction::class)->execute($subscription->id, $currentPlan->id, '2026-10-02');
    }

    public function test_plan_change_rejects_future_effective_date(): void
    {
        [$merchant, $customer, $oldPlan] = $this->merchantCustomerAndPlan();
        $newPlan = $this->plan($merchant, 'Growth');
        $subscription = $this->subscription($merchant, $customer, $oldPlan, '2026-09-01');

        $this->expectException(ValidationException::class);

        app(ChangePlanAction::class)->execute($subscription->id, $newPlan->id, '2026-10-03');
    }

    public function test_plan_change_rejects_effective_date_in_an_invoiced_cycle(): void
    {
        [$merchant, $customer, $oldPlan] = $this->merchantCustomerAndPlan();
        $newPlan = $this->plan($merchant, 'Growth');
        $subscription = $this->subscription($merchant, $customer, $oldPlan, '2026-09-01');

        DB::table('invoices')->insert([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'invoice_number' => 'TEST-'.$subscription->id.'-202609',
            'cycle_start' => '2026-09-01',
            'cycle_end' => '2026-09-30',
            'base_amount_paise' => 0,
            'overage_amount_paise' => 0,
            'total_amount_paise' => 0,
            'currency' => 'INR',
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        app(ChangePlanAction::class)->execute($subscription->id, $newPlan->id, '2026-09-15');
    }

    private function merchantCustomerAndPlan(): array
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);
        $plan = $this->plan($merchant, 'Starter');

        return [$merchant, $customer, $plan];
    }

    private function plan(Merchant $merchant, string $name): Plan
    {
        return Plan::factory()->create([
            'merchant_id' => $merchant->id,
            'name' => $name.' '.uniqid(),
        ]);
    }

    private function subscription(
        Merchant $merchant,
        Customer $customer,
        Plan $plan,
        string $startsAt,
    ): Subscription {
        $subscription = Subscription::query()->create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'current_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => $startsAt,
            'ends_at' => null,
        ]);

        $subscription->segments()->create([
            'plan_id' => $plan->id,
            'starts_at' => $startsAt,
            'ends_at' => null,
        ]);

        return $subscription;
    }

    private function usage(
        Merchant $merchant,
        Customer $customer,
        string $idempotencyKey,
        int $units,
        string $occurredAt,
    ): void {
        UsageEvent::query()->create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'idempotency_key' => $idempotencyKey,
            'units' => $units,
            'occurred_at' => $occurredAt,
            'created_at' => now(),
        ]);
    }
}
