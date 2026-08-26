<?php

namespace App\Http\Middleware;

use App\Models\AuditoriaLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AuditApiRequestMiddleware
{
    /**
     * Parametros de query que no deben quedar registrados en claro.
     */
    private const REDACTED_QUERY_KEYS = ['signature', 'token', 'api_key'];

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('audit_started_at', microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $this->shouldAudit($request, $response)) {
            return;
        }

        $payload = $this->payload($request, $response);
        $tenantSchema = $request->attributes->get('tenant_schema');

        if (is_string($tenantSchema) && preg_match('/^[a-zA-Z0-9_]+$/', $tenantSchema) && $this->storeInTenantSchema($tenantSchema, $payload, $request, $response)) {
            return;
        }

        Log::channel('audit')->info('api_request', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return bool true si la auditoria quedo persistida en el esquema del tenant
     */
    private function storeInTenantSchema(
        string $tenantSchema,
        array $payload,
        Request $request,
        Response $response,
    ): bool {
        $usesPostgres = Str::contains((string) DB::connection()->getDriverName(), 'pgsql');

        try {
            if ($usesPostgres) {
                DB::statement(sprintf('SET search_path TO "%s", public', $tenantSchema));
            }

            AuditoriaLog::query()->insert([
                'action' => 'api_request',
                'documento_id' => null,
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'performed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        } catch (\Throwable $exception) {
            Log::channel('audit')->warning('No se pudo guardar auditoria API en tabla.', [
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
                'message' => $exception->getMessage(),
            ]);

            return false;
        } finally {
            // Imprescindible restaurarlo: con conexiones persistentes (Octane,
            // FrankenPHP o un pool) la siguiente peticion heredaria el esquema
            // de este tenant y leeria los datos de otro.
            if ($usesPostgres) {
                DB::statement('SET search_path TO public');
            }
        }
    }

    private function shouldAudit(Request $request, Response $response): bool
    {
        if (! $request->is('api/*')) {
            return false;
        }

        if ($request->is('api/auth/login')) {
            return $response->getStatusCode() >= 400;
        }

        return ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            || $response->getStatusCode() >= 400;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, Response $response): array
    {
        $startedAt = $request->attributes->get('audit_started_at');
        $duration = is_float($startedAt) ? (microtime(true) - $startedAt) * 1000 : null;

        return [
            'method' => $request->method(),
            'path' => '/'.$request->path(),
            'route' => $request->route()?->getName(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $duration !== null ? round($duration, 2) : null,
            'tenant_id' => $request->attributes->get('tenant_id'),
            'tenant_ruc' => $request->attributes->get('tenant_ruc'),
            'auth_mode' => $request->attributes->get('auth_mode'),
            'auth_user_id' => $request->attributes->get('auth_user_id'),
            'api_client_id' => $request->attributes->get('api_client_id'),
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'query' => $this->redactQuery($request->query()),
        ];
    }

    /**
     * La firma de una URL temporal es una credencial: quien la lea en la
     * auditoria puede volver a descargar el comprobante hasta que expire.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function redactQuery(array $query): array
    {
        foreach (self::REDACTED_QUERY_KEYS as $key) {
            if (array_key_exists($key, $query)) {
                $query[$key] = '[redacted]';
            }
        }

        return $query;
    }
}
