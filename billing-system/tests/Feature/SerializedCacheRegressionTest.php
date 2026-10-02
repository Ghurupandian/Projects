<?php

namespace Tests\Feature;

use App\Actions\GetMerchantDashboardAction;
use App\Models\Merchant;
use App\Models\Plan;
use App\Services\PlanService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SerializedCacheRegressionTest extends TestCase
{
    use DatabaseTransactions;

    private string $originalCacheDefault;

    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalCacheDefault = (string) config('cache.default');
        $this->cachePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'billing-cache-'.bin2hex(random_bytes(8));

        config([
            'cache.default' => 'serialized_file',
            'cache.stores.serialized_file' => [
                'driver' => 'file',
                'path' => $this->cachePath,
                'lock_path' => $this->cachePath,
            ],
        ]);
        Cache::purge('serialized_file');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::purge('serialized_file');
        config(['cache.default' => $this->originalCacheDefault]);
        File::deleteDirectory($this->cachePath);

        parent::tearDown();
    }

    public function test_cached_plan_and_dashboard_values_work_with_a_serializing_store(): void
    {
        $this->assertFalse((bool) config('cache.serializable_classes'));

        $apiKey = 'serialized-cache-regression-key';
        $merchant = Merchant::factory()->withApiKey($apiKey)->create();
        $plan = Plan::factory()->create([
            'merchant_id' => $merchant->id,
            'name' => 'Serialized cache plan',
            'is_active' => true,
        ]);

        $planService = app(PlanService::class);

        $this->assertSame($plan->id, $planService->find($merchant->id, $plan->id)?->id);
        $this->assertSame($plan->id, $planService->find($merchant->id, $plan->id)?->id);
        $this->assertSame($plan->id, $planService->getActivePlansForMerchant($merchant->id)->first()?->id);
        $this->assertSame($plan->id, $planService->getActivePlansForMerchant($merchant->id)->first()?->id);

        $dashboardAction = app(GetMerchantDashboardAction::class);
        $this->assertSame($merchant->id, $dashboardAction->execute($merchant->id)->data['merchant_id']);
        $this->assertSame($merchant->id, $dashboardAction->execute($merchant->id)->data['merchant_id']);

        $this->getJson('/api/ping', ['X-API-Key' => $apiKey])->assertOk();
        $this->getJson('/api/ping', ['X-API-Key' => $apiKey])->assertOk();
    }
}
