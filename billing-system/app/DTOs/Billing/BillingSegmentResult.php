<?php

namespace App\DTOs\Billing;

class BillingSegmentResult
{
    public function __construct(
        public readonly int $planId,
        public readonly string $segmentStart,
        public readonly string $segmentEnd,
        public readonly int $daysInSegment,
        public readonly int $daysInCycle,
        public readonly int $unitsUsed,
        public readonly int $allowanceUnitsNumerator,
        public readonly int $overageUnitsNumerator,
        public readonly int $baseAmountPaise,
        public readonly int $overageAmountPaise,
        public readonly int $subtotalPaise,
    ) {}
}
