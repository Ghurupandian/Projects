<?php

namespace App\DTOs\Billing;

class BillingCalculationInput
{
    /**
     * @param  list<BillingSegmentInput>  $segments
     * @param  list<DailyUsageInput>  $dailyUsages
     */
    public function __construct(
        public readonly string $cycleStart,
        public readonly string $cycleEnd,
        public readonly string $subscriptionStart,
        public readonly ?string $subscriptionEnd,
        public readonly array $segments,
        public readonly array $dailyUsages,
    ) {}
}
