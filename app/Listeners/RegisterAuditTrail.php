<?php

namespace App\Listeners;

use App\Domain\Documentos\Events\DocumentoProcesado;
use App\Domain\Documentos\Events\DocumentoRecibido;
use App\Infrastructure\Audit\AuditLogger;

final class RegisterAuditTrail
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function onDocumentoRecibido(DocumentoRecibido $event): void
    {
        $this->auditLogger->log('documento_recibido', [
            'documento_id' => $event->documentoId,
        ], $event->documentoId);
    }

    public function onDocumentoProcesado(DocumentoProcesado $event): void
    {
        $this->auditLogger->log('documento_procesado', [
            'estado' => $event->estado,
        ], $event->documentoId);
    }
}