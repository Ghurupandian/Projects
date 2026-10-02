<?php

namespace App\Http\Controllers\Api;

use App\Actions\RecordUsageAction;
use App\Http\Requests\RecordUsageRequest;
use App\Http\Resources\UsageEventResource;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class UsageController
{
    public function __construct(
        private readonly RecordUsageAction $action,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Ingest a usage event (idempotent).
     *
     * POST /api/usage
     * POST /usage
     *
     * Returns 201 on first ingestion, 200 on idempotent replay.
     */
    public function store(RecordUsageRequest $request): JsonResponse
    {
        $merchant = $this->tenantContext->get();
        $dto = $request->toDto($merchant->id);

        $result = $this->action->execute($dto);

        JsonResource::withoutWrapping();

        $resource = new UsageEventResource($result->usageEvent, $result->customerReference);
        $status = $result->isReplay ? 200 : 201;
        $headers = $result->isReplay ? ['Idempotent-Replay' => 'true'] : [];

        return $resource->response()->setStatusCode($status)->withHeaders($headers);
    }
}
