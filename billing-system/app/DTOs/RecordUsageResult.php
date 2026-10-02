<?php

namespace App\DTOs;

use App\Models\UsageEvent;

class RecordUsageResult
{
    public function __construct(
        public readonly UsageEvent $usageEvent,
        public readonly string $customerReference,
        public readonly bool $isReplay = false,
    ) {}
}
