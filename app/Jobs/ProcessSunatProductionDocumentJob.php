<?php

namespace App\Jobs;

final class ProcessSunatProductionDocumentJob extends AbstractProcessSunatDocumentJob
{
    public function __construct(
        int $documentoId,
        int $tenantId,
        string $tenantRuc,
        string $tenantSchema,
    ) {
        parent::__construct(
            documentoId: $documentoId,
            tenantId: $tenantId,
            tenantRuc: $tenantRuc,
            tenantSchema: $tenantSchema,
            sunatMode: 'production',
            queueName: (string) config('facturador.sunat.queues.production', 'sunat-production'),
        );
    }
}

