<?php

namespace App\Actions;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\PlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateSubscriptionAction
{
    public function __construct(private readonly PlanService $planService) {}

    public function execute(int $customerId, int $planId, string $startsAt): Subscription
    {
        $subscription = DB::transaction(function () use ($customerId, $planId, $startsAt): Subscription {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);
            $plan = Plan::query()->findOrFail($planId);

            if ($plan->merchant_id !== $customer->merchant_id || ! $plan->is_active) {
                throw ValidationException::withMessages([
                    'plan_id' => 'The selected plan is not active for this customer\'s merchant.',
                ]);
            }

            $hasActiveSubscription = Subscription::query()
                ->where('customer_id', $customer->id)
                ->where('status', 'active')
                ->where(function ($query) use ($startsAt): void {
                    $query->whereNull('ends_at')
                        ->orWhere('ends_at', '>=', $startsAt);
                })
                ->exists();

            if ($hasActiveSubscription) {
                throw ValidationException::withMessages([
                    'customer_id' => 'The customer already has an active subscription.',
                ]);
            }

            $subscription = Subscription::query()->create([
                'merchant_id' => $customer->merchant_id,
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
        });

        $this->planService->invalidatePlanCache($subscription->merchant_id);

        return $subscription;
    }
}
