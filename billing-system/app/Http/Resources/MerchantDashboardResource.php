<?php

namespace App\Http\Resources;

use App\DTOs\MerchantDashboardData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantDashboardResource extends JsonResource
{
    public function __construct(MerchantDashboardData $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MerchantDashboardData $data */
        $data = $this->resource;

        return $data->data;
    }
}
