<?php

namespace App\Application\Tenants\UseCases;

use App\Models\Tenant;
use App\Support\Sunat\SunatEnvironment;
use App\Support\Tenants\TenantPrivateFileReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ShowTenantUseCase
{
    public function execute(int $tenantId): array
    {
        $tenant = Tenant::query()->findOrFail($tenantId);

        $environment = SunatEnvironment::describe((string) $tenant->sunat_mode);
        $config = $this->loadConfig(
            (string) $tenant->schema_name,
            (string) $tenant->ruc,
            $environment,
        );

        return [
            'tenant_id' => $tenant->id,
            'ruc' => $tenant->ruc,
            'business_name' => $tenant->business_name,
            'schema' => $tenant->schema_name,
            'sunat_mode' => $tenant->sunat_mode,
            'external_tenant_id' => $tenant->external_tenant_id,
            'country_code' => $tenant->country_code,
            'tax_id' => $tenant->tax_id,
            'document_mode' => $tenant->document_mode,
            'fiscal_status' => $tenant->fiscal_status,
            'ticket_enabled' => (bool) $tenant->is_active,
            'electronic_documents_enabled' => $tenant->allowsElectronicDocuments(),
            'is_active' => (bool) $tenant->is_active,
            'api_key' => null,
            'entorno_sunat' => $environment,
            'configuracion' => $config,
        ];
    }

    private function loadConfig(string $schema, string $tenantRuc, array $environment): array
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

        $usesTestData = (bool) ($environment['usa_datos_prueba'] ?? false);

        return [
            'ruc_sol' => $usesTestData ? '20000000001' : $row->ruc_sol,
            'usuario_sol' => $usesTestData ? 'MODDATOS' : $row->usuario_sol,
            'usa_datos_prueba' => $usesTestData,
            'endpoint_facturacion' => $environment['endpoint_facturacion'] ?? null,
            'endpoint_guias' => $environment['endpoint_guias'] ?? null,
            'cola' => $environment['cola'] ?? null,
            'certificado_configurado' => $usesTestData
                ? is_file(storage_path('certificates/ejemplo123456789.pem'))
                : TenantPrivateFileReference::isAvailable(
                    $tenantRuc,
                    'certificados',
                    $row->certificado_url ?? null,
                ),
            'certificado_produccion_configurado' => TenantPrivateFileReference::isProductionCertificateAvailable(
                $tenantRuc,
                $row->certificado_url ?? null,
            ),
            'modo_sunat' => $environment['modo'] ?? $row->modo_sunat,
            'logo_pdf_configurado' => TenantPrivateFileReference::isAvailable(
                $tenantRuc,
                'logos',
                $row->logo_pdf_url ?? null,
            ),
            'serie_factura' => $row->serie_factura,
            'serie_boleta' => $row->serie_boleta,
            'serie_nc' => $row->serie_nc,
            'serie_nd' => $row->serie_nd,
            'serie_guia' => $row->serie_guia,
            'igv' => $row->igv,
            'moneda' => $row->moneda,
        ];
    }

}
