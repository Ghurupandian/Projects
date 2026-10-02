<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DailyUsage;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\SubscriptionSegment;
use App\Models\UsageEvent;
use Carbon\Carbon;
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

    public function test_demo_seeder_creates_usage_dashboard_data_and_september_invoices(): void
    {
        app(DemoDataSeeder::class)->run();

        $merchant = Merchant::query()->where('name', 'Acme Cloud Corp')->sole();
        $customers = Customer::query()->where('merchant_id', $merchant->id)->get();

        $this->assertCount(4, $customers);
        $this->assertSame(4, Invoice::query()
            ->where('merchant_id', $merchant->id)
            ->where('cycle_start', '2026-09-01')
            ->where('cycle_end', '2026-09-30')
            ->count());
        $this->assertGreaterThan(0, DailyUsage::query()
            ->where('merchant_id', $merchant->id)
            ->whereBetween('usage_date', ['2026-10-01', '2026-10-31'])
            ->count());
        $this->assertSame(4 * 32, UsageEvent::query()
            ->where('merchant_id', $merchant->id)
            ->count());

        $asha = $customers->firstWhere('customer_reference', 'demo-customer-001');
        $ravi = $customers->firstWhere('customer_reference', 'demo-customer-002');
        $this->assertLessThan(
            DailyUsage::query()->where('customer_id', $asha->id)->whereBetween('usage_date', ['2026-09-01', '2026-09-30'])->sum('total_units') / 2,
            DailyUsage::query()->where('customer_id', $asha->id)->whereBetween('usage_date', ['2026-10-01', '2026-10-31'])->sum('total_units'),
        );
        $this->assertLessThan(
            DailyUsage::query()->where('customer_id', $ravi->id)->whereBetween('usage_date', ['2026-09-01', '2026-09-30'])->sum('total_units') / 2,
            DailyUsage::query()->where('customer_id', $ravi->id)->whereBetween('usage_date', ['2026-10-01', '2026-10-31'])->sum('total_units'),
        );

        $mira = $customers->firstWhere('customer_reference', 'demo-customer-003');
        $this->assertSame(2, SubscriptionSegment::query()
            ->where('subscription_id', $mira->subscriptions()->sole()->id)
            ->count());
    }
}
