<?php

namespace App\Infrastructure\Audit;

use App\Models\AuditoriaLog;
use Illuminate\Support\Facades\Log;

final class AuditLogger
{
    public function log(string $action, array $payload = [], ?int $documentoId = null): void
    {
        try {
            AuditoriaLog::query()->insert([
                'action' => $action,
                'documento_id' => $documentoId,
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'performed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Log::channel('audit')->warning('No se pudo guardar auditoria de documento.', [
                'action' => $action,
                'documento_id' => $documentoId,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
