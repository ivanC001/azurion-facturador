<?php

namespace App\Infrastructure\Tenant;

use App\Support\Tenants\TenantContext;

final class TenantStoragePathResolver
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function basePath(): string
    {
        return 'tenants/'.$this->tenantContext->required()->ruc;
    }

    public function xmlPath(string $fileName): string
    {
        return $this->basePath().'/xml/'.$fileName;
    }

    public function pdfPath(string $fileName): string
    {
        return $this->basePath().'/pdf/'.$fileName;
    }

    public function cdrPath(string $fileName): string
    {
        return $this->basePath().'/cdr/'.$fileName;
    }

    public function certPath(string $fileName): string
    {
        return $this->basePath().'/certificados/'.$fileName;
    }
}