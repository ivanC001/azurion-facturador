<?php

namespace App\Support\Tenants;

use App\Support\Tenants\TenantIdentity;

final class TenantContext
{
    private ?TenantIdentity $tenant = null;

    public function set(TenantIdentity $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?TenantIdentity
    {
        return $this->tenant;
    }

    public function required(): TenantIdentity
    {
        if ($this->tenant === null) {
            throw new \RuntimeException('Tenant context is not available for this request.');
        }

        return $this->tenant;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }
}
