<?php

namespace App\Events;

use App\Models\Plan;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlanChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Plan $plan,
        public readonly string $action // 'created', 'updated', 'deleted'
    ) {}
}
