<?php

namespace App\DTOs;

use Carbon\CarbonInterface;

class UsageEventData
{
    public function __construct(
        public readonly int $merchantId,
        public readonly string $idempotencyKey,
        public readonly string $customerReference,
        public readonly int $units,
        public readonly CarbonInterface $occurredAt,
        public readonly bool $explicitOccurredAt,
    ) {}
}
