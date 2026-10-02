<?php

namespace App\Services;

use App\Models\Merchant;

class TenantContext
{
    private ?Merchant $merchant = null;

    /**
     * Set the current tenant (Merchant).
     */
    public function set(Merchant $merchant): void
    {
        $this->merchant = $merchant;
    }

    /**
     * Get the current tenant (Merchant).
     */
    public function get(): ?Merchant
    {
        return $this->merchant;
    }

    /**
     * Get the current tenant ID.
     */
    public function id(): ?int
    {
        return $this->merchant?->id;
    }

    /**
     * Check if a tenant is currently authenticated.
     */
    public function check(): bool
    {
        return $this->merchant !== null;
    }

    /**
     * Clear the tenant context.
     */
    public function clear(): void
    {
        $this->merchant = null;
    }
}
