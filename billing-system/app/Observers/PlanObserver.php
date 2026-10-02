<?php

namespace App\Observers;

use App\Events\PlanChanged;
use App\Models\Plan;

class PlanObserver
{
    /**
     * Handle the Plan "created" event.
     */
    public function created(Plan $plan): void
    {
        event(new PlanChanged($plan, 'created'));
    }

    /**
     * Handle the Plan "updated" event.
     */
    public function updated(Plan $plan): void
    {
        event(new PlanChanged($plan, 'updated'));
    }

    /**
     * Handle the Plan "deleted" event.
     */
    public function deleted(Plan $plan): void
    {
        event(new PlanChanged($plan, 'deleted'));
    }
}
