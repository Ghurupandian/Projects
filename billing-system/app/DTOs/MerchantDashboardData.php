<?php

namespace App\DTOs;

class MerchantDashboardData
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public readonly array $data) {}
}
