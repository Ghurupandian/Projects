<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Plan;
use App\Services\PlanService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AuthenticationAndPlanCachingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_rejects_request_without_api_key(): void
    {
        $response = $this->getJson('/api/ping');

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'Unauthorized',
                'message' => 'Missing X-API-Key header.',
            ]);
    }

    public function test_rejects_request_with_invalid_api_key(): void
    {
        $response = $this->getJson('/api/ping', [
            'X-API-Key' => 'invalid-key-12345',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'Unauthorized',
                'message' => 'Invalid API key.',
            ]);
    }

    public function test_authenticates_merchant_with_valid_api_key(): void
    {
        $plainKey = 'sk_test_validkey123';
        $merchant = Merchant::factory()->withApiKey($plainKey)->create([
            'name' => 'Test Tenant',
        ]);

        $plan = Plan::factory()->create([
            'merchant_id' => $merchant->id,
            'name' => 'Growth Plan',
            'base_price_paise' => 200000,
            'included_units' => 5000,
            'overage_rate_paise' => 25,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/ping', [
            'X-API-Key' => $plainKey,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'pong',
                'tenant' => [
                    'id' => $merchant->id,
                    'name' => 'Test Tenant',
                ],
                'active_plans_count' => 1,
            ]);
    }

    public function test_plan_observer_invalidates_cache_on_change(): void
    {
        $plainKey = 'sk_test_cachekey123';
        $merchant = Merchant::factory()->withApiKey($plainKey)->create();

        $plan = Plan::factory()->create([
            'merchant_id' => $merchant->id,
            'name' => 'Initial Plan',
            'is_active' => true,
        ]);

        $planService = app(PlanService::class);

        // Version after creation
        $initialVersion = $planService->getPlanVersion($merchant->id);

        // Initial fetch caches plan
        $cachedPlan = $planService->find($merchant->id, $plan->id);
        $this->assertEquals('Initial Plan', $cachedPlan->name);

        // Update plan triggers PlanObserver -> PlanChanged -> InvalidatePlanCacheListener
        $plan->update(['name' => 'Updated Plan']);

        $newVersion = $planService->getPlanVersion($merchant->id);
        $this->assertGreaterThan($initialVersion, $newVersion);

        // Fresh find uses new version key
        $updatedCachedPlan = $planService->find($merchant->id, $plan->id);
        $this->assertEquals('Updated Plan', $updatedCachedPlan->name);
    }

    public function test_rate_limiter_enforces_120_requests_per_minute_with_retry_after_header(): void
    {
        $plainKey = 'sk_test_ratelimit123';
        $merchant = Merchant::factory()->withApiKey($plainKey)->create();

        // Exhaust the 120 requests limit
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/ping', ['X-API-Key' => $plainKey]);
        }

        // The 121st request must receive HTTP 429 with Retry-After header
        $response = $this->getJson('/api/ping', [
            'X-API-Key' => $plainKey,
        ]);

        $response->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJson([
                'error' => 'Too Many Requests',
                'message' => 'Rate limit of 120 requests per minute exceeded.',
            ]);
    }
}
