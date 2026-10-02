<?php

namespace App\DTOs\Billing;

class BillingSegmentInput
{
    public function __construct(
        public readonly int $planId,
        public readonly string $startsAt,
        public readonly ?string $endsAt,
        public readonly int $basePricePaise,
        public readonly int $includedUnits,
        public readonly int $overageRatePaise,
    ) {}
}
