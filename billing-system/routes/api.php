<?php

use App\Http\Controllers\Api\UsageController;
use App\Services\PlanService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth.api_key', 'throttle:api-key'])->group(function () {
    Route::get('/ping', function (Request $request, TenantContext $tenantContext, PlanService $planService) {
        $merchant = $tenantContext->get();
        $plans = $planService->getActivePlansForMerchant($merchant->id);
        $version = $planService->getPlanVersion($merchant->id);

        return response()->json([
            'message' => 'pong',
            'tenant' => [
                'id' => $merchant->id,
                'name' => $merchant->name,
            ],
            'cached_plan_version' => $version,
            'active_plans_count' => $plans->count(),
            'plans' => $plans->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'billing_cycle' => $p->billing_cycle,
                'base_price_paise' => $p->base_price_paise,
                'included_units' => $p->included_units,
                'overage_rate_paise' => $p->overage_rate_paise,
            ]),
        ]);
    });

    // POST /api/usage  — primary route (API prefix added by Laravel api route file)
    Route::post('/usage', [UsageController::class, 'store']);
});
