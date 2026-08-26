<?php

namespace App\Application\Documentos\Services;

use App\Models\Tenant;
use App\Support\Tenants\TenantContext;
use Illuminate\Validation\ValidationException;

final class DocumentEmissionPolicy
{
    private const ELECTRONIC_TYPES = ['01', '03', '07', '08', '09', 'RC'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function assertAllowed(string $documentType): void
    {
        $type = strtoupper(trim($documentType));
        $tenant = $this->tenantContext->required();

        if ($type === 'TK') {
            return;
        }

        if (! in_array($type, self::ELECTRONIC_TYPES, true)) {
            throw ValidationException::withMessages([
                'documento.tipo' => ['Tipo de documento no soportado.'],
            ]);
        }

        if (strtoupper($tenant->countryCode) !== 'PE') {
            throw ValidationException::withMessages([
                'documento.tipo' => ['Boletas, facturas y documentos electronicos SUNAT solo estan disponibles para empresas de Peru. Emite un ticket de venta.'],
            ]);
        }

        if ($tenant->documentMode !== Tenant::DOCUMENT_MODE_ELECTRONIC
            || $tenant->fiscalStatus !== Tenant::FISCAL_STATUS_ACTIVE
            || ! in_array($tenant->sunatMode, [Tenant::SUNAT_MODE_BETA, Tenant::SUNAT_MODE_PRODUCTION], true)) {
            throw ValidationException::withMessages([
                'documento.tipo' => ['La facturacion electronica no esta activa. Completa la configuracion fiscal o emite un ticket de venta.'],
            ]);
        }
    }
}
