<?php

namespace App\Application\Integrations\Azurion;

use App\Models\Documento;
use App\Support\Documentos\SignedArtifactUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class AzurionVentaStatusNotifier
{
    public function isEnabled(): bool
    {
        return (bool) config('facturador.integrations.azurion.enabled', false);
    }

    public function notify(Documento $documento): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $callbackUrl = $this->resolveCallbackUrl($documento);
        $apiKey = trim((string) config('facturador.integrations.azurion.api_key', ''));
        $sharedSecret = trim((string) config('facturador.integrations.azurion.shared_secret', ''));

        if ($callbackUrl === '' || $apiKey === '' || $sharedSecret === '') {
            Log::warning('Callback AZURION deshabilitado por configuracion incompleta.', [
                'has_url' => $callbackUrl !== '',
                'has_api_key' => $apiKey !== '',
                'has_secret' => $sharedSecret !== '',
            ]);

            return false;
        }

        try {
            $documento->loadMissing('sunat');
            $payload = $this->buildPayload($documento);
            $jsonBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($jsonBody === false) {
                Log::warning('No se pudo serializar payload callback AZURION.', [
                    'documento_id' => $documento->id,
                ]);

                return false;
            }

            $requestUri = $this->resolveRequestUri($callbackUrl);
            $timestamp = (string) now()->timestamp;
            $nonce = Str::random(24);

            $signature = $this->signBase64(
                method: 'POST',
                requestUri: $requestUri,
                timestamp: $timestamp,
                nonce: $nonce,
                rawBody: $jsonBody,
                secret: $sharedSecret,
            );

            $headerApiKey = (string) config('facturador.integrations.azurion.header_api_key', 'X-API-Key');
            $headerSignature = (string) config('facturador.integrations.azurion.header_signature', 'X-Signature');
            $headerTimestamp = (string) config('facturador.integrations.azurion.header_timestamp', 'X-Timestamp');
            $headerNonce = (string) config('facturador.integrations.azurion.header_nonce', 'X-Nonce');
            $connectTimeout = max(1, (int) config('facturador.integrations.azurion.connect_timeout_seconds', 3));
            $timeout = max(2, (int) config('facturador.integrations.azurion.timeout_seconds', 8));

            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                $headerApiKey => $apiKey,
                $headerTimestamp => $timestamp,
                $headerNonce => $nonce,
                $headerSignature => $signature,
            ])
                ->connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->send('POST', $callbackUrl, [
                    'body' => $jsonBody,
                ]);

            if (! $response->successful()) {
                Log::warning('Callback AZURION respondio con error.', [
                    'documento_id' => $documento->id,
                    'status' => $response->status(),
                    // Solo un extracto: el cuerpo completo puede arrastrar datos
                    // del ERP hacia los logs del facturador.
                    'response' => Str::limit($response->body(), 500),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Fallo callback AZURION.', [
                'documento_id' => $documento->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function resolveCallbackUrl(Documento $documento): string
    {
        $fallback = trim((string) config('facturador.integrations.azurion.callback_url', ''));
        $urls = config('facturador.integrations.azurion.callback_urls', []);
        $urls = is_array($urls) ? $urls : [];

        $tipo = strtoupper(trim((string) ($documento->tipo_documento ?? '')));
        $key = match ($tipo) {
            '09' => 'guias',
            '07' => 'notas_credito',
            '08' => 'notas_debito',
            '01', '03', 'TK' => 'ventas',
            default => 'documentos',
        };

        $candidate = trim((string) ($urls[$key] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }

        return $fallback;
    }

    private function buildPayload(Documento $documento): array
    {
        $documentoId = (int) $documento->id;
        $externalId = trim((string) data_get($documento->payload, 'documento.external_id', ''));
        $empresaRuc = trim((string) data_get($documento->empresa, 'ruc', data_get($documento->payload, 'empresa.ruc', '')));
        $tenantRuc = $empresaRuc !== '' ? $empresaRuc : '00000000000';
        $isTicket = strtoupper((string) $documento->tipo_documento) === 'TK';

        return [
            'event' => 'DOCUMENT_STATUS_UPDATED',
            'externalId' => $externalId !== '' ? $externalId : null,
            'tenantRuc' => $empresaRuc !== '' ? $empresaRuc : null,
            'documentoId' => $documentoId,
            'tipoDocumento' => $documento->tipo_documento,
            'serie' => $documento->serie,
            'correlativo' => $documento->correlativo,
            'estado' => strtoupper((string) $documento->estado),
            'sunatEstado' => $this->normalizeUpper($documento->sunat?->estado),
            'sunatCodigo' => $this->normalizeString($documento->sunat?->codigo_error),
            'sunatMensaje' => $this->normalizeString($documento->sunat?->mensaje),
            'ticket' => $this->normalizeString($documento->ticket),
            'hash' => $this->normalizeString($documento->hash),
            'pdfUrl' => $documentoId > 0 ? $this->signedArtifactUrl('documentos.pdf', $documentoId, $tenantRuc) : null,
            'xmlUrl' => ($documentoId > 0 && ! $isTicket) ? $this->signedArtifactUrl('documentos.xml', $documentoId, $tenantRuc) : null,
            'cdrUrl' => ($documentoId > 0 && ! $isTicket) ? $this->signedArtifactUrl('documentos.cdr', $documentoId, $tenantRuc) : null,
            'updatedAt' => now()->toIso8601String(),
            'documento' => [
                'id' => $documentoId,
                'external_id' => $externalId !== '' ? $externalId : null,
                'tipo' => $documento->tipo_documento,
                'serie' => $documento->serie,
                'correlativo' => $documento->correlativo,
            ],
            'sunat' => [
                'estado' => $this->normalizeUpper($documento->sunat?->estado),
                'codigo' => $this->normalizeString($documento->sunat?->codigo_error),
                'mensaje' => $this->normalizeString($documento->sunat?->mensaje),
                'ticket' => $this->normalizeString($documento->ticket),
            ],
        ];
    }

    private function signedArtifactUrl(string $routeName, int $documentId, string $tenantRuc): string
    {
        return SignedArtifactUrl::for($routeName, $documentId, $tenantRuc);
    }

    private function resolveRequestUri(string $callbackUrl): string
    {
        $parts = parse_url($callbackUrl);
        $path = (string) ($parts['path'] ?? '/');
        $query = (string) ($parts['query'] ?? '');

        if ($query === '') {
            return $path;
        }

        return $path.'?'.$query;
    }

    private function signBase64(
        string $method,
        string $requestUri,
        string $timestamp,
        string $nonce,
        string $rawBody,
        string $secret,
    ): string {
        $bodyHash = hash('sha256', $rawBody);
        $canonical = implode("\n", [
            strtoupper($method),
            $requestUri,
            $timestamp,
            $nonce,
            $bodyHash,
        ]);

        $binary = hash_hmac('sha256', $canonical, $secret, true);

        return base64_encode($binary);
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeUpper(mixed $value): ?string
    {
        $normalized = $this->normalizeString($value);
        if ($normalized === null) {
            return null;
        }

        return mb_strtoupper($normalized);
    }
}
