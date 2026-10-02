<?php

namespace App\Listeners;

use App\Events\PlanChanged;
use App\Services\PlanService;

class InvalidatePlanCacheListener
{
    public function __construct(
        private readonly PlanService $planService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(PlanChanged $event): void
    {
        $this->planService->invalidatePlanCache($event->plan->merchant_id);
    }
}
