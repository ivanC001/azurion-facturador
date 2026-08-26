<?php

namespace App\Jobs;

final class ProcessSunatBetaDocumentJob extends AbstractProcessSunatDocumentJob
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
            sunatMode: 'beta',
            queueName: (string) config('facturador.sunat.queues.beta', 'sunat-beta'),
        );
    }
}
