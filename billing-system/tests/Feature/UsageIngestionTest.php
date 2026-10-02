<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageEvent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class UsageIngestionTest extends TestCase
{
    use DatabaseTransactions;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function merchant(string $plainKey): Merchant
    {
        return Merchant::factory()->withApiKey($plainKey)->create();
    }

    private function customerWithSubscription(Merchant $merchant): Customer
    {
        $plan = Plan::factory()->create(['merchant_id' => $merchant->id]);

        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);

        Subscription::factory()->create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'current_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => Carbon::now()->subMonth()->startOfMonth()->toDateString(),
            'ends_at' => null,
        ]);

        return $customer;
    }

    private function postUsage(array $body = [], array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/usage', $body, $headers);
    }

    // -------------------------------------------------------------------------
    // 1. First call → 201, correct JSON shape (no "data" wrapper)
    // -------------------------------------------------------------------------
    public function test_first_call_returns_201_with_correct_shape(): void
    {
        $key = 'sk_test_'.uniqid();
        $merchant = $this->merchant($key);
        $customer = $this->customerWithSubscription($merchant);

        $response = $this->postUsage(
            ['customer_reference' => $customer->customer_reference, 'units' => 10],
            ['X-API-Key' => $key, 'Idempotency-Key' => 'idem-'.uniqid()]
        );

        $response->assertStatus(201)
            ->assertJsonStructure([
                'idempotency_key',
                'customer_reference',
                'units',
                'occurred_at',
                'recorded_at',
            ])
            ->assertJson([
                'customer_reference' => $customer->customer_reference,
                'units' => 10,
            ]);

        // No "data" wrapper
        $this->assertArrayNotHasKey('data', $response->json());
        $this->assertNull($response->headers->get('Idempotent-Replay'));
    }

    // -------------------------------------------------------------------------
    // 2. Identical retry → 200, Idempotent-Replay: true, no double-count
    // -------------------------------------------------------------------------
    public function test_retry_returns_200_with_replay_header_and_no_double_count(): void
    {
        $key = 'sk_test_'.uniqid();
        $merchant = $this->merchant($key);
        $customer = $this->customerWithSubscription($merchant);
        $idempotencyKey = 'idem-'.uniqid();
        $headers = ['X-API-Key' => $key, 'Idempotency-Key' => $idempotencyKey];
        $body = ['customer_reference' => $customer->customer_reference, 'units' => 5];

        $first = $this->postUsage($body, $headers);
        $first->assertStatus(201);

        $retry = $this->postUsage($body, $headers);
        $retry->assertStatus(200)
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJson(['units' => 5, 'customer_reference' => $customer->customer_reference]);

        $this->assertEquals(1, UsageEvent::where('idempotency_key', $idempotencyKey)->count());
    }

    // -------------------------------------------------------------------------
    // 3. Same Idempotency-Key, different payload → 409
    // -------------------------------------------------------------------------
    public function test_conflicting_payload_returns_409(): void
    {
        $key = 'sk_test_'.uniqid();
        $merchant = $this->merchant($key);
        $customer = $this->customerWithSubscription($merchant);
        $idempotencyKey = 'idem-'.uniqid();
        $headers = ['X-API-Key' => $key, 'Idempotency-Key' => $idempotencyKey];

        $this->postUsage(['customer_reference' => $customer->customer_reference, 'units' => 10], $headers)
            ->assertStatus(201);

        $this->postUsage(['customer_reference' => $customer->customer_reference, 'units' => 99], $headers)
            ->assertStatus(409);
    }

    // -------------------------------------------------------------------------
    // 4. Missing Idempotency-Key header → 422
    // -------------------------------------------------------------------------
    public function test_missing_idempotency_key_returns_422(): void
    {
        $key = 'sk_test_'.uniqid();
        $merchant = $this->merchant($key);
        $customer = $this->customerWithSubscription($merchant);

        $this->postUsage(
            ['customer_reference' => $customer->customer_reference, 'units' => 5],
            ['X-API-Key' => $key]
        )->assertStatus(422)
            ->assertJsonPath('errors.idempotency_key.0', 'The Idempotency-Key header is required.');
    }

    // -------------------------------------------------------------------------
    // 5. Unknown customer → 404
    // -------------------------------------------------------------------------
    public function test_unknown_customer_returns_404(): void
    {
        $key = 'sk_test_'.uniqid();
        $merchant = $this->merchant($key);

        $this->postUsage(
            ['customer_reference' => 'no_such_customer', 'units' => 1],
            ['X-API-Key' => $key, 'Idempotency-Key' => 'idem-'.uniqid()]
        )->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // 6. No covering subscription → 422
    // -------------------------------------------------------------------------
    public function test_no_covering_subscription_returns_422(): void
    {
        $key = 'sk_test_'.uniqid();
        $merchant = $this->merchant($key);
        $plan = Plan::factory()->create(['merchant_id' => $merchant->id]);
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);

        // Subscription starts tomorrow — does not cover today
        Subscription::factory()->create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'current_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => Carbon::tomorrow()->toDateString(),
            'ends_at' => null,
        ]);

        $this->postUsage(
            [
                'customer_reference' => $customer->customer_reference,
                'units' => 1,
                'occurred_at' => Carbon::now()->toIso8601String(),
            ],
            ['X-API-Key' => $key, 'Idempotency-Key' => 'idem-'.uniqid()]
        )->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // 7. occurred_at more than 5 minutes in the future → 422
    // -------------------------------------------------------------------------
    public function test_future_timestamp_beyond_5_minutes_returns_422(): void
    {
        $key = 'sk_test_'.uniqid();
        $merchant = $this->merchant($key);
        $customer = $this->customerWithSubscription($merchant);

        $this->postUsage(
            [
                'customer_reference' => $customer->customer_reference,
                'units' => 1,
                'occurred_at' => Carbon::now()->addMinutes(10)->toIso8601String(),
            ],
            ['X-API-Key' => $key, 'Idempotency-Key' => 'idem-'.uniqid()]
        )->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // 8. Tenant isolation — merchant A cannot post for merchant B's customer
    // -------------------------------------------------------------------------
    public function test_merchant_cannot_post_usage_for_another_merchants_customer(): void
    {
        $keyA = 'sk_test_a_'.uniqid();
        $keyB = 'sk_test_b_'.uniqid();
        $merchantA = $this->merchant($keyA);
        $merchantB = $this->merchant($keyB);

        $customerB = $this->customerWithSubscription($merchantB);

        // Merchant A uses merchant B's customer_reference
        $this->postUsage(
            ['customer_reference' => $customerB->customer_reference, 'units' => 1],
            ['X-API-Key' => $keyA, 'Idempotency-Key' => 'idem-'.uniqid()]
        )->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // 9. Replay with omitted occurred_at — should not return 409
    // -------------------------------------------------------------------------
    public function test_replay_with_omitted_occurred_at_returns_200_not_409(): void
    {
        $key = 'sk_test_'.uniqid();
        $merchant = $this->merchant($key);
        $customer = $this->customerWithSubscription($merchant);
        $idempotencyKey = 'idem-'.uniqid();
        $headers = ['X-API-Key' => $key, 'Idempotency-Key' => $idempotencyKey];

        // First call — no occurred_at
        $this->postUsage(
            ['customer_reference' => $customer->customer_reference, 'units' => 7],
            $headers
        )->assertStatus(201);

        // Retry — no occurred_at again (now() will differ; should NOT be compared)
        $this->postUsage(
            ['customer_reference' => $customer->customer_reference, 'units' => 7],
            $headers
        )->assertStatus(200)->assertHeader('Idempotent-Replay', 'true');
    }

    // -------------------------------------------------------------------------
    // 10. UTC normalisation — offset timestamp stored as UTC
    // -------------------------------------------------------------------------
    public function test_offset_timestamp_is_stored_as_utc(): void
    {
        $key = 'sk_test_'.uniqid();
        $merchant = $this->merchant($key);
        $customer = $this->customerWithSubscription($merchant);
        $idempotencyKey = 'idem-'.uniqid();

        // A timestamp supplied in IST (+05:30). Equivalent UTC must be stored.
        $base = Carbon::now()->subHours(2)->setTimezone('Asia/Kolkata');
        $offsetTs = $base->toIso8601String();                       // e.g. …+05:30
        $expectedUtc = $base->utc()->format('Y-m-d H:i:s');

        $response = $this->postUsage(
            [
                'customer_reference' => $customer->customer_reference,
                'units' => 3,
                'occurred_at' => $offsetTs,
            ],
            ['X-API-Key' => $key, 'Idempotency-Key' => $idempotencyKey]
        );

        $response->assertStatus(201);

        $stored = UsageEvent::where('idempotency_key', $idempotencyKey)->value('occurred_at');
        $this->assertEquals($expectedUtc, $stored);
    }
}
