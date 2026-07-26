<?php

namespace App\Application\Tenants\UseCases;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ShowTenantUseCase
{
    public function execute(int $tenantId): array
    {
        $tenant = Tenant::query()->findOrFail($tenantId);

        $config = $this->loadConfig((string) $tenant->schema_name);

        return [
            'tenant_id' => $tenant->id,
            'ruc' => $tenant->ruc,
            'business_name' => $tenant->business_name,
            'schema' => $tenant->schema_name,
            'sunat_mode' => $tenant->sunat_mode,
            'is_active' => (bool) $tenant->is_active,
            'api_key' => $config['token_api'] ?? null,
            'configuracion' => $config,
        ];
    }

    private function loadConfig(string $schema): array
    {
        if (! Str::contains((string) DB::connection()->getDriverName(), 'pgsql')) {
            return [];
        }

        if (! preg_match('/^[a-zA-Z0-9_]+$/', $schema)) {
            return [];
        }

        try {
            $row = DB::table($schema.'.configuracion_facturacion')->find(1);
        } catch (\Throwable) {
            return [];
        }

        if ($row === null) {
            return [];
        }

        return [
            'ruc_sol' => $row->ruc_sol,
            'usuario_sol' => $row->usuario_sol,
            'certificado_url' => $row->certificado_url,
            'certificado_password' => $row->certificado_password,
            'modo_sunat' => $row->modo_sunat,
            'logo_pdf_url' => $row->logo_pdf_url,
            'serie_factura' => $row->serie_factura,
            'serie_boleta' => $row->serie_boleta,
            'serie_nc' => $row->serie_nc,
            'serie_nd' => $row->serie_nd,
            'serie_guia' => $row->serie_guia,
            'igv' => $row->igv,
            'moneda' => $row->moneda,
            'token_api' => $row->token_api,
        ];
    }
}

