<?php

namespace App\Application\Tenants\UseCases;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ListTenantsUseCase
{
    public function execute(): array
    {
        $tenants = Tenant::query()
            ->orderByDesc('id')
            ->get();

        $items = $tenants->map(function (Tenant $tenant): array {
            $apiKey = null;

            try {
                if (
                    Str::contains((string) DB::connection()->getDriverName(), 'pgsql')
                    && preg_match('/^[a-zA-Z0-9_]+$/', (string) $tenant->schema_name)
                ) {
                    $apiKey = DB::table($tenant->schema_name.'.configuracion_facturacion')
                        ->value('token_api');
                }
            } catch (\Throwable) {
                $apiKey = null;
            }

            return [
                'tenant_id' => $tenant->id,
                'ruc' => $tenant->ruc,
                'business_name' => $tenant->business_name,
                'schema' => $tenant->schema_name,
                'sunat_mode' => $tenant->sunat_mode,
                'is_active' => (bool) $tenant->is_active,
                'api_key' => $apiKey,
            ];
        })->values()->all();

        return [
            'total' => count($items),
            'items' => $items,
        ];
    }
}

