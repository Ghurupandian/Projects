<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DailyUsage;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\SubscriptionSegment;
use App\Models\UsageEvent;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
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

    public function test_demo_seeder_creates_relative_month_usage_and_previous_month_invoices(): void
    {
        app(DemoDataSeeder::class)->run();

        $today = CarbonImmutable::today('UTC');
        $previousMonthStart = $today->subMonthNoOverflow()->startOfMonth();
        $previousMonthEnd = $previousMonthStart->endOfMonth();
        $currentMonthStart = $today->startOfMonth();
        $merchant = Merchant::query()->where('name', 'Acme Cloud Corp')->sole();
        $customers = Customer::query()->where('merchant_id', $merchant->id)->get();

        $this->assertCount(4, $customers);
        $this->assertSame(4, Invoice::query()
            ->where('merchant_id', $merchant->id)
            ->where('cycle_start', $previousMonthStart->toDateString())
            ->where('cycle_end', $previousMonthEnd->toDateString())
            ->count());
        $this->assertGreaterThan(0, DailyUsage::query()
            ->where('merchant_id', $merchant->id)
            ->whereBetween('usage_date', [$currentMonthStart->toDateString(), $today->toDateString()])
            ->count());
        $this->assertSame(4 * ($previousMonthEnd->day + $today->day), UsageEvent::query()
            ->where('merchant_id', $merchant->id)
            ->count());

        $asha = $customers->firstWhere('customer_reference', 'demo-customer-001');
        $ravi = $customers->firstWhere('customer_reference', 'demo-customer-002');
        $this->assertLessThan(
            DailyUsage::query()->where('customer_id', $asha->id)->whereBetween('usage_date', [$previousMonthStart->toDateString(), $previousMonthEnd->toDateString()])->sum('total_units') / 2,
            DailyUsage::query()->where('customer_id', $asha->id)->whereBetween('usage_date', [$currentMonthStart->toDateString(), $today->toDateString()])->sum('total_units'),
        );
        $this->assertLessThan(
            DailyUsage::query()->where('customer_id', $ravi->id)->whereBetween('usage_date', [$previousMonthStart->toDateString(), $previousMonthEnd->toDateString()])->sum('total_units') / 2,
            DailyUsage::query()->where('customer_id', $ravi->id)->whereBetween('usage_date', [$currentMonthStart->toDateString(), $today->toDateString()])->sum('total_units'),
        );

        $mira = $customers->firstWhere('customer_reference', 'demo-customer-003');
        $miraSubscription = $mira->subscriptions()->sole();
        $this->assertSame(2, SubscriptionSegment::query()->where('subscription_id', $miraSubscription->id)->count());
        $this->assertSame(
            2,
            Invoice::query()
                ->where('subscription_id', $miraSubscription->id)
                ->where('cycle_start', $previousMonthStart->toDateString())
                ->firstOrFail()
                ->lineItems()
                ->count(),
        );
        $this->assertTrue(
            SubscriptionSegment::query()
                ->where('subscription_id', $miraSubscription->id)
                ->whereDate('starts_at', $previousMonthStart->day(16)->toDateString())
                ->exists(),
        );
    }
}
