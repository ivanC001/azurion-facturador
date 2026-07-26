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

        try {
            if (is_string($tenantSchema) && preg_match('/^[a-zA-Z0-9_]+$/', $tenantSchema)) {
                if (Str::contains((string) DB::connection()->getDriverName(), 'pgsql')) {
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

                return;
            }
        } catch (\Throwable $exception) {
            Log::channel('audit')->warning('No se pudo guardar auditoria API en tabla.', [
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
                'message' => $exception->getMessage(),
            ]);
        }

        Log::channel('audit')->info('api_request', $payload);
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
            'query' => $request->query(),
        ];
    }
}
