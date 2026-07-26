<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Tenants\TenantIdentity;
use App\Support\Tenants\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTenantMiddleware
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolveTenant($request);

        if ($tenant === null) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant could not be resolved.',
            ], 400);
        }

        $this->tenantContext->set(new TenantIdentity(
            tenantId: $tenant->id,
            ruc: $tenant->ruc,
            schema: $tenant->schema_name,
            sunatMode: $tenant->sunat_mode,
        ));
        $request->attributes->set('tenant_id', $tenant->id);
        $request->attributes->set('tenant_ruc', $tenant->ruc);
        $request->attributes->set('tenant_schema', $tenant->schema_name);
        $request->attributes->set('tenant_sunat_mode', $tenant->sunat_mode);

        return $next($request);
    }

    private function resolveTenant(Request $request): ?Tenant
    {
        $tenantId = $request->attributes->get('tenant_id');

        if ($tenantId !== null) {
            return Cache::remember(
                'facturador:tenant:id:'.$tenantId,
                now()->addMinutes(10),
                fn (): ?Tenant => Tenant::query()
                    ->whereKey($tenantId)
                    ->where('is_active', true)
                    ->first(),
            );
        }

        $ruc = $request->header('X-Tenant-RUC');
        if (! is_string($ruc) || trim($ruc) === '') {
            $ruc = $request->query('tenant_ruc');
        }
        if (! is_string($ruc) || trim($ruc) === '') {
            $ruc = $request->query('ruc');
        }

        if (! is_string($ruc) || trim($ruc) === '') {
            return null;
        }

        $ruc = trim($ruc);

        return Cache::remember(
            'facturador:tenant:ruc:'.$ruc,
            now()->addMinutes(10),
            fn (): ?Tenant => Tenant::query()
                ->where('ruc', $ruc)
                ->where('is_active', true)
                ->first(),
        );
    }
}
