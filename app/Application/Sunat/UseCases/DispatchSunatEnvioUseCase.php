<?php

namespace App\Application\Sunat\UseCases;

use App\Jobs\ProcessSunatBetaDocumentJob;
use App\Jobs\ProcessSunatProductionDocumentJob;
use App\Support\Tenants\TenantContext;

final class DispatchSunatEnvioUseCase
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function execute(int $documentoId): void
    {
        $tenant = $this->tenantContext->required();

        if ($tenant->sunatMode === 'production') {
            ProcessSunatProductionDocumentJob::dispatch(
                documentoId: $documentoId,
                tenantId: $tenant->tenantId,
                tenantRuc: $tenant->ruc,
                tenantSchema: $tenant->schema,
            );

            return;
        }

        ProcessSunatBetaDocumentJob::dispatch(
            documentoId: $documentoId,
            tenantId: $tenant->tenantId,
            tenantRuc: $tenant->ruc,
            tenantSchema: $tenant->schema,
        );
    }
}
