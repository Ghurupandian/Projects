<?php

namespace App\DTOs;

use Carbon\CarbonInterface;

class UsageEventData
{
    public readonly CarbonInterface $occurredAt;

    public function __construct(
        public readonly int $merchantId,
        public readonly string $idempotencyKey,
        public readonly string $customerReference,
        public readonly int $units,
        CarbonInterface $occurredAt,
        public readonly bool $explicitOccurredAt,
    ) {
        // Always normalise to UTC regardless of what timezone the caller passes
        $this->occurredAt = $occurredAt->utc();
    }
}
