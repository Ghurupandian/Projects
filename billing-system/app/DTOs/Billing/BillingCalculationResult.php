<?php

namespace App\DTOs\Billing;

class BillingCalculationResult
{
    /**
     * @param  list<BillingSegmentResult>  $segments
     */
    public function __construct(
        public readonly array $segments,
        public readonly int $baseAmountPaise,
        public readonly int $overageAmountPaise,
        public readonly int $totalAmountPaise,
    ) {}
}
