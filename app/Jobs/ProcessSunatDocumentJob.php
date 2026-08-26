<?php

namespace App\Jobs;

/**
 * Compatibilidad hacia atras: usa cola y modo segun sunatMode recibido.
 */
class ProcessSunatDocumentJob extends AbstractProcessSunatDocumentJob
{
    public function __construct(
        int $documentoId,
        int $tenantId,
        string $tenantRuc,
        string $tenantSchema,
        string $sunatMode = 'beta',
    ) {
        $queueName = $sunatMode === 'production'
            ? (string) config('facturador.sunat.queues.production', 'sunat-production')
            : (string) config('facturador.sunat.queues.beta', 'sunat-beta');

        parent::__construct(
            documentoId: $documentoId,
            tenantId: $tenantId,
            tenantRuc: $tenantRuc,
            tenantSchema: $tenantSchema,
            sunatMode: $sunatMode,
            queueName: $queueName,
        );
    }
}
