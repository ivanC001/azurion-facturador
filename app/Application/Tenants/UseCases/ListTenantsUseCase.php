<?php

namespace App\Application\Tenants\UseCases;

use App\Models\Tenant;
use App\Support\Sunat\SunatEnvironment;
use App\Support\Tenants\TenantPrivateFileReference;
use Illuminate\Support\Facades\DB;

final class ListTenantsUseCase
{
    public function execute(?int $tenantId = null): array
    {
        $query = Tenant::query()->orderByDesc('id');
        if ($tenantId !== null) {
            $query->whereKey($tenantId);
        }
        $tenants = $query->get();

        $items = $tenants->map(function (Tenant $tenant): array {
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
                'entorno_sunat' => SunatEnvironment::describe((string) $tenant->sunat_mode),
                'certificado_configurado' => $tenant->sunat_mode === Tenant::SUNAT_MODE_BETA
                    ? is_file(storage_path('certificates/ejemplo123456789.pem'))
                    : $this->hasStoredFile(
                        $tenant,
                        'certificado_url',
                        'certificados',
                    ),
                'certificado_produccion_configurado' => $this->hasProductionCertificate($tenant),
                'logo_pdf_configurado' => $this->hasStoredFile($tenant, 'logo_pdf_url', 'logos'),
            ];
        })->values()->all();

        return [
            'total' => count($items),
            'items' => $items,
        ];
    }

    private function hasStoredFile(Tenant $tenant, string $column, string $directory): bool
    {
        $schema = (string) $tenant->schema_name;
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $schema)
            || ! in_array($column, ['certificado_url', 'logo_pdf_url'], true)) {
            return false;
        }

        try {
            $value = DB::table($schema.'.configuracion_facturacion')->orderBy('id')->value($column);
        } catch (\Throwable) {
            return false;
        }

        return TenantPrivateFileReference::isAvailable($tenant->ruc, $directory, $value);
    }

    private function hasProductionCertificate(Tenant $tenant): bool
    {
        $schema = (string) $tenant->schema_name;
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $schema)) {
            return false;
        }

        try {
            $value = DB::table($schema.'.configuracion_facturacion')
                ->orderBy('id')
                ->value('certificado_url');
        } catch (\Throwable) {
            return false;
        }

        return TenantPrivateFileReference::isProductionCertificateAvailable($tenant->ruc, $value);
    }
}
