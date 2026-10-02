<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class PlanService
{
    /**
     * Cache TTL for plan data (10 minutes).
     */
    private const CACHE_TTL_SECONDS = 600;

    /**
     * Get the current plan cache version for a merchant.
     * The version counter key never expires.
     */
    public function getPlanVersion(int $merchantId): int
    {
        $versionKey = "merchant:{$merchantId}:plans_version";

        return (int) Cache::rememberForever($versionKey, fn () => 1);
    }

    /**
     * Invalidate the merchant's plan cache by incrementing the version counter.
     */
    public function invalidatePlanCache(int $merchantId): void
    {
        $versionKey = "merchant:{$merchantId}:plans_version";

        if (Cache::has($versionKey)) {
            Cache::increment($versionKey);
        } else {
            Cache::forever($versionKey, 2);
        }
    }

    /**
     * Find a plan for a specific merchant, cached using the merchant's versioned key.
     * Enforces tenant isolation.
     */
    public function find(int $merchantId, int $planId): ?Plan
    {
        $version = $this->getPlanVersion($merchantId);
        $cacheKey = "merchant:{$merchantId}:plan:{$planId}:v{$version}";

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($merchantId, $planId) {
            return Plan::where('merchant_id', $merchantId)
                ->where('id', $planId)
                ->first();
        });
    }

    /**
     * Get all active plans for a merchant, cached using the merchant's versioned key.
     *
     * @return Collection<int, Plan>
     */
    public function getActivePlansForMerchant(int $merchantId): Collection
    {
        $version = $this->getPlanVersion($merchantId);
        $cacheKey = "merchant:{$merchantId}:active_plans:v{$version}";

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($merchantId) {
            return Plan::where('merchant_id', $merchantId)
                ->where('is_active', true)
                ->get();
        });
    }
}
