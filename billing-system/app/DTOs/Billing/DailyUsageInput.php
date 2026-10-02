<?php

namespace App\DTOs\Billing;

class DailyUsageInput
{
    public function __construct(
        public readonly string $usageDate,
        public readonly int $totalUnits,
    ) {}
}
