<?php

namespace Tests\Feature;

use App\Actions\ChangePlanAction;
use App\Actions\CreateSubscriptionAction;
use App\Actions\GenerateSubscriptionInvoiceAction;
use App\Actions\RecomputeCycleDailyUsageAction;
use App\Jobs\AggregateDailyUsageJob;
use App\Jobs\GenerateSubscriptionInvoicesJob;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageEvent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class InvoiceGenerationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_invoice_generation_is_idempotent_when_chunk_job_runs_twice(): void
    {
        [$merchant, $customer, $plan, $subscription] = $this->subscribedCustomer();
        $this->usage($merchant, $customer, '2026-10-05', 25);

        $job = new GenerateSubscriptionInvoicesJob([$subscription->id], '2026-10-01', '2026-10-31');
        $job->handle(
            app(RecomputeCycleDailyUsageAction::class),
            app(GenerateSubscriptionInvoiceAction::class),
        );
        $job->handle(
            app(RecomputeCycleDailyUsageAction::class),
            app(GenerateSubscriptionInvoiceAction::class),
        );

        $this->assertSame(1, Invoice::query()
            ->where('subscription_id', $subscription->id)
            ->where('cycle_start', '2026-10-01')
            ->where('cycle_end', '2026-10-31')
            ->count());
        $this->assertSame(1, Invoice::query()->where('subscription_id', $subscription->id)->first()->lineItems()->count());
    }

    public function test_late_event_after_invoice_is_logged_and_not_added_to_daily_usage(): void
    {
        [$merchant, $customer, $plan, $subscription] = $this->subscribedCustomer();
        $this->usage($merchant, $customer, '2026-10-01', 10);
        $this->runInvoiceChunk($subscription);
        $this->usage($merchant, $customer, '2026-10-02', 20);

        Log::spy();
        (new AggregateDailyUsageJob('2026-10-02'))->handle();

        $this->assertDatabaseMissing('daily_usages', [
            'customer_id' => $customer->id,
            'usage_date' => '2026-10-02',
        ]);
        Log::shouldHaveReceived('warning')->once()->with(
            'Usage events for an already-invoiced cycle were excluded from aggregation.',
            ['usage_date' => '2026-10-02', 'event_count' => 1],
        );
    }

    public function test_end_to_end_invoice_calculates_mid_cycle_plan_change_line_items(): void
    {
        [$merchant, $customer, $starter, $subscription] = $this->subscribedCustomer([
            'name' => 'Starter',
            'base_price_paise' => 50000,
            'included_units' => 5000,
            'overage_rate_paise' => 20,
        ], '2026-10-01');
        $growth = $this->plan($merchant, 'Growth', 100000, 10000, 10);

        app(ChangePlanAction::class)->execute($subscription->id, $growth->id, '2026-10-16');

        $this->usage($merchant, $customer, '2026-10-10', 2000);
        $this->usage($merchant, $customer, '2026-10-20', 6000);
        $this->runInvoiceChunk($subscription);

        $invoice = Invoice::query()->where('subscription_id', $subscription->id)->sole();
        $lineItems = $invoice->lineItems()->orderBy('segment_start')->get();

        $this->assertSame(84194, $invoice->total_amount_paise);
        $this->assertCount(2, $lineItems);
        $this->assertSame('2026-10-01', $lineItems[0]->segment_start->toDateString());
        $this->assertSame('2026-10-15', $lineItems[0]->segment_end->toDateString());
        $this->assertSame(2000, $lineItems[0]->units_used);
        $this->assertSame(24194, $lineItems[0]->base_amount_paise);
        $this->assertSame(0, $lineItems[0]->overage_amount_paise);
        $this->assertSame('2026-10-16', $lineItems[1]->segment_start->toDateString());
        $this->assertSame('2026-10-31', $lineItems[1]->segment_end->toDateString());
        $this->assertSame(6000, $lineItems[1]->units_used);
        $this->assertSame(160000, $lineItems[1]->units_included_numerator);
        $this->assertSame(26000, $lineItems[1]->overage_units_numerator);
        $this->assertSame(5161, $lineItems[1]->units_included);
        $this->assertSame(839, $lineItems[1]->overage_units);
        $this->assertSame(51613, $lineItems[1]->base_amount_paise);
        $this->assertSame(8387, $lineItems[1]->overage_amount_paise);
    }

    public function test_failing_subscription_does_not_stop_other_subscriptions_in_chunk(): void
    {
        [$merchant, $failedCustomer, $plan, $failedSubscription] = $this->subscribedCustomer();
        [$otherMerchant, $goodCustomer, $goodPlan, $goodSubscription] = $this->subscribedCustomer();

        $failedSubscription->segments()->whereNull('ends_at')->update(['starts_at' => '2026-10-02']);
        $this->usage($merchant, $failedCustomer, '2026-10-01', 10);
        $this->usage($otherMerchant, $goodCustomer, '2026-10-01', 15);

        Log::spy();
        $this->runInvoiceChunk([$failedSubscription, $goodSubscription]);

        $this->assertDatabaseMissing('invoices', ['subscription_id' => $failedSubscription->id]);
        $this->assertDatabaseHas('invoices', ['subscription_id' => $goodSubscription->id]);
        Log::shouldHaveReceived('error')->once();
    }

    /**
     * @return array{Merchant, Customer, Plan, Subscription}
     */
    private function subscribedCustomer(
        array $planOverrides = [],
        string $startsAt = '2026-09-01',
    ): array {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);
        $plan = $this->plan(
            $merchant,
            $planOverrides['name'] ?? 'Starter',
            $planOverrides['base_price_paise'] ?? 10000,
            $planOverrides['included_units'] ?? 100,
            $planOverrides['overage_rate_paise'] ?? 5,
        );
        $subscription = app(CreateSubscriptionAction::class)->execute($customer->id, $plan->id, $startsAt);

        return [$merchant, $customer, $plan, $subscription];
    }

    private function plan(
        Merchant $merchant,
        string $name,
        int $basePaise,
        int $includedUnits,
        int $overageRate,
    ): Plan {
        return Plan::factory()->create([
            'merchant_id' => $merchant->id,
            'name' => $name.'-'.uniqid(),
            'base_price_paise' => $basePaise,
            'included_units' => $includedUnits,
            'overage_rate_paise' => $overageRate,
        ]);
    }

    private function usage(Merchant $merchant, Customer $customer, string $date, int $units): void
    {
        UsageEvent::query()->create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'idempotency_key' => 'test-'.$customer->id.'-'.$date.'-'.uniqid(),
            'units' => $units,
            'occurred_at' => $date.' 12:00:00',
            'created_at' => now(),
        ]);
    }

    private function runInvoiceChunk(Subscription|array $subscriptions): void
    {
        $subscriptions = is_array($subscriptions) ? $subscriptions : [$subscriptions];
        $job = new GenerateSubscriptionInvoicesJob(
            array_map(static fn (Subscription $subscription): int => (int) $subscription->id, $subscriptions),
            '2026-10-01',
            '2026-10-31',
        );
        $job->handle(
            app(RecomputeCycleDailyUsageAction::class),
            app(GenerateSubscriptionInvoiceAction::class),
        );
    }
}
