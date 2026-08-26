<?php

namespace App\Support\Tenants;

final class TenantIdentity
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $ruc,
        public readonly string $schema,
        public readonly string $sunatMode,
        public readonly string $countryCode,
        public readonly string $documentMode,
        public readonly string $fiscalStatus,
    ) {}
}
