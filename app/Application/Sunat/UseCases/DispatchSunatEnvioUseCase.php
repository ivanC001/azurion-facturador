<?php

namespace App\Application\Sunat\UseCases;

use App\Jobs\ProcessSunatBetaDocumentJob;
use App\Jobs\ProcessSunatProductionDocumentJob;
use App\Models\Tenant;
use App\Support\Tenants\TenantContext;
use Illuminate\Validation\ValidationException;

final class DispatchSunatEnvioUseCase
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function execute(int $documentoId): void
    {
        $tenant = $this->tenantContext->required();

        if (strtoupper($tenant->countryCode) !== 'PE'
            || $tenant->documentMode !== Tenant::DOCUMENT_MODE_ELECTRONIC
            || $tenant->fiscalStatus !== Tenant::FISCAL_STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'sunat' => ['El tenant no tiene habilitada la facturacion electronica SUNAT.'],
            ]);
        }

        if ($tenant->sunatMode === Tenant::SUNAT_MODE_PRODUCTION) {
            ProcessSunatProductionDocumentJob::dispatch(
                documentoId: $documentoId,
                tenantId: $tenant->tenantId,
                tenantRuc: $tenant->ruc,
                tenantSchema: $tenant->schema,
            );

            return;
        }

        if ($tenant->sunatMode === Tenant::SUNAT_MODE_BETA) {
            ProcessSunatBetaDocumentJob::dispatch(
                documentoId: $documentoId,
                tenantId: $tenant->tenantId,
                tenantRuc: $tenant->ruc,
                tenantSchema: $tenant->schema,
            );

            return;
        }

        throw ValidationException::withMessages([
            'sunat_mode' => ['Modo SUNAT invalido o deshabilitado.'],
        ]);
    }
}
