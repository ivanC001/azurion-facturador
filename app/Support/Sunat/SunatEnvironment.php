<?php

namespace App\Support\Sunat;

use App\Models\Tenant;
use Greenter\Ws\Services\SunatEndpoints;

final class SunatEnvironment
{
    public static function endpoint(string $mode, string $documentType = '03'): ?string
    {
        $mode = strtolower(trim($mode));
        if (! in_array($mode, [Tenant::SUNAT_MODE_BETA, Tenant::SUNAT_MODE_PRODUCTION], true)) {
            return null;
        }

        if ($documentType === '09') {
            $fallback = $mode === Tenant::SUNAT_MODE_PRODUCTION
                ? SunatEndpoints::GUIA_PRODUCCION
                : SunatEndpoints::GUIA_BETA;
            $configured = $mode === Tenant::SUNAT_MODE_PRODUCTION
                ? config('services.sunat.guia_production_url', $fallback)
                : config('services.sunat.guia_beta_url', $fallback);

            return self::validatedUrl($configured, $fallback);
        }

        $fallback = $mode === Tenant::SUNAT_MODE_PRODUCTION
            ? SunatEndpoints::FE_PRODUCCION
            : SunatEndpoints::FE_BETA;
        $configured = $mode === Tenant::SUNAT_MODE_PRODUCTION
            ? config('services.sunat.production_url', $fallback)
            : config('services.sunat.beta_url', $fallback);

        return self::validatedUrl($configured, $fallback);
    }

    public static function queue(string $mode): ?string
    {
        return match (strtolower(trim($mode))) {
            Tenant::SUNAT_MODE_BETA => (string) config('facturador.sunat.queues.beta', 'sunat-beta'),
            Tenant::SUNAT_MODE_PRODUCTION => (string) config('facturador.sunat.queues.production', 'sunat-production'),
            default => null,
        };
    }

    /**
     * @return array{
     *     modo: string,
     *     usa_datos_prueba: bool,
     *     endpoint_facturacion: ?string,
     *     endpoint_guias: ?string,
     *     cola: ?string
     * }
     */
    public static function describe(string $mode): array
    {
        $mode = strtolower(trim($mode));

        return [
            'modo' => $mode,
            'usa_datos_prueba' => $mode === Tenant::SUNAT_MODE_BETA,
            'endpoint_facturacion' => self::endpoint($mode),
            'endpoint_guias' => self::endpoint($mode, '09'),
            'cola' => self::queue($mode),
        ];
    }

    private static function validatedUrl(mixed $configured, string $fallback): string
    {
        $endpoint = trim(is_string($configured) ? $configured : '');

        return filter_var($endpoint, FILTER_VALIDATE_URL) !== false ? $endpoint : $fallback;
    }
}
