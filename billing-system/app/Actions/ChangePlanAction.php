<?php

namespace App\Actions;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionSegment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangePlanAction
{
    public function execute(int $subscriptionId, int $newPlanId, string $effectiveDate): Subscription
    {
        return DB::transaction(function () use ($subscriptionId, $newPlanId, $effectiveDate): Subscription {
            $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscriptionId);
            $newPlan = Plan::query()->findOrFail($newPlanId);

            if ($newPlan->merchant_id !== $subscription->merchant_id || ! $newPlan->is_active) {
                throw ValidationException::withMessages([
                    'plan_id' => 'The selected plan is not active for this subscription\'s merchant.',
                ]);
            }

            $date = $this->parseEffectiveDate($effectiveDate);
            $openSegment = SubscriptionSegment::query()
                ->where('subscription_id', $subscription->id)
                ->whereNull('ends_at')
                ->lockForUpdate()
                ->first();

            if ($openSegment === null) {
                throw ValidationException::withMessages([
                    'subscription_id' => 'The subscription has no open plan segment.',
                ]);
            }

            if ($newPlan->id === $openSegment->plan_id) {
                throw ValidationException::withMessages([
                    'plan_id' => 'The selected plan is already the subscription\'s current plan.',
                ]);
            }

            $segmentStart = CarbonImmutable::parse($openSegment->starts_at->toDateString());

            if ($date->lessThanOrEqualTo($segmentStart)) {
                throw ValidationException::withMessages([
                    'effective_date' => 'The effective date must be after the current segment start date.',
                ]);
            }

            if ($date->isAfter(CarbonImmutable::today())) {
                throw ValidationException::withMessages([
                    'effective_date' => 'The effective date cannot be in the future.',
                ]);
            }

            $cycleStart = $date->startOfMonth()->toDateString();
            $cycleEnd = $date->endOfMonth()->toDateString();
            $alreadyInvoiced = DB::table('invoices')
                ->where('subscription_id', $subscription->id)
                ->where('cycle_start', $cycleStart)
                ->where('cycle_end', $cycleEnd)
                ->exists();

            if ($alreadyInvoiced) {
                throw ValidationException::withMessages([
                    'effective_date' => 'A plan cannot be changed in an already-invoiced cycle.',
                ]);
            }

            $openSegment->update([
                'ends_at' => $date->subDay()->toDateString(),
            ]);

            $subscription->segments()->create([
                'plan_id' => $newPlan->id,
                'starts_at' => $date->toDateString(),
                'ends_at' => null,
            ]);

            $subscription->update(['current_plan_id' => $newPlan->id]);

            return $subscription->refresh();
        });
    }

    private function parseEffectiveDate(string $effectiveDate): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $effectiveDate);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([
                'effective_date' => 'The effective date must be a valid YYYY-MM-DD date.',
            ]);
        }

        if ($date === false || $date->format('Y-m-d') !== $effectiveDate) {
            throw ValidationException::withMessages([
                'effective_date' => 'The effective date must be a valid YYYY-MM-DD date.',
            ]);
        }

        return $date;
    }
}
