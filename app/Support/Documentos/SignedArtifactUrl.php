<?php

namespace App\Support\Documentos;

use Illuminate\Support\Facades\URL;

/**
 * Genera los enlaces temporales a PDF, XML y CDR de un comprobante.
 *
 * Estaba reimplementado en tres sitios (DocumentoController,
 * CreateDocumentoUseCase y AzurionVentaStatusNotifier) con vigencias
 * distintas: dos fijaban 30 minutos a mano e ignoraban
 * facturador.artifacts.signed_url_ttl_minutes, que solo respetaba el tercero.
 */
final class SignedArtifactUrl
{
    private const MIN_TTL_MINUTES = 5;

    private const DEFAULT_TTL_MINUTES = 30;

    public static function for(
        string $routeName,
        int $documentId,
        string $tenantRuc,
        ?string $pdfFormat = null,
    ): string {
        $parameters = ['id' => $documentId, 'tenant_ruc' => $tenantRuc];

        if ($routeName === 'documentos.pdf' && $pdfFormat !== null) {
            $parameters['formato'] = $pdfFormat;
        }

        return URL::temporarySignedRoute(
            $routeName,
            now()->addMinutes(self::ttlMinutes()),
            $parameters,
        );
    }

    public static function ttlMinutes(): int
    {
        return max(
            self::MIN_TTL_MINUTES,
            (int) config('facturador.artifacts.signed_url_ttl_minutes', self::DEFAULT_TTL_MINUTES),
        );
    }
}
