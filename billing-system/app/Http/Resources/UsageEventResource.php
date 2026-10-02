<?php

namespace App\Http\Resources;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class UsageEventResource extends JsonResource
{
    public function __construct(
        $resource,
        private readonly string $customerReference
    ) {
        parent::__construct($resource);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'idempotency_key' => $this->idempotency_key,
            'customer_reference' => $this->customerReference,
            'units' => (int) $this->units,
            'occurred_at' => $this->formatIso($this->occurred_at),
            'recorded_at' => $this->formatIso($this->created_at ?? now()),
        ];
    }

    private function formatIso(mixed $date): string
    {
        if ($date instanceof CarbonInterface) {
            return $date->toISOString();
        }

        return Carbon::parse($date)->toISOString();
    }
}
