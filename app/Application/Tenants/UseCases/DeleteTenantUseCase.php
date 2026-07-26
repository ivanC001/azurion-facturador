<?php

namespace App\Application\Tenants\UseCases;

use App\Models\ApiClient;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

final class DeleteTenantUseCase
{
    public function execute(int $tenantId): array
    {
        $tenant = Tenant::query()->findOrFail($tenantId);

        $tenant->forceFill(['is_active' => false])->save();

        ApiClient::query()
            ->where('tenant_id', $tenant->id)
            ->update(['is_active' => false]);
        Cache::forget('facturador:tenant:id:'.$tenant->id);
        Cache::forget('facturador:tenant:ruc:'.$tenant->ruc);

        return [
            'tenant_id' => $tenant->id,
            'ruc' => $tenant->ruc,
            'schema' => $tenant->schema_name,
            'deleted' => true,
            'is_active' => false,
        ];
    }
}
