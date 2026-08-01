<?php

namespace Tests\Feature;

use App\Application\Tenants\UseCases\DeleteTenantUseCase;
use App\Http\Middleware\ResolveTenantMiddleware;
use App\Http\Middleware\SetTenantSearchPathMiddleware;
use App\Models\Tenant;
use App\Support\Tenants\TenantContext;
use App\Support\Tenants\TenantIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class TenantIsolationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_artifact_tenant_is_never_overridden_by_headers(): void
    {
        $signedTenant = $this->tenant('20600000001', 'tenant_signed', 'external-signed');
        $headerTenant = $this->tenant('20600000002', 'tenant_header', 'external-header');
        $request = Request::create(
            '/api/documentos/1/pdf?tenant_ruc='.$signedTenant->ruc,
            'GET',
        );
        $request->headers->set('X-Azurion-Tenant-ID', $headerTenant->external_tenant_id);
        $request->headers->set('X-Tenant-RUC', $headerTenant->ruc);
        $request->attributes->set('tenant_id', $headerTenant->id);

        $route = new Route(['GET'], '/api/documentos/{id}/pdf', static fn () => null);
        $route->name('documentos.pdf');
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);

        $response = app(ResolveTenantMiddleware::class)->handle(
            $request,
            static fn (Request $resolved) => response()->json([
                'tenant_id' => $resolved->attributes->get('tenant_id'),
                'tenant_ruc' => $resolved->attributes->get('tenant_ruc'),
            ]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($signedTenant->id, $response->getData(true)['tenant_id']);
        self::assertSame($signedTenant->ruc, $response->getData(true)['tenant_ruc']);
    }

    public function test_deactivation_invalidates_every_tenant_identity_cache_key(): void
    {
        $tenant = $this->tenant('20600000003', 'tenant_cache', 'external-cache');
        Cache::put('facturador:tenant:id:'.$tenant->id, $tenant, 600);
        Cache::put('facturador:tenant:ruc:'.$tenant->ruc, $tenant, 600);
        Cache::put('facturador:tenant:external:'.$tenant->external_tenant_id, $tenant, 600);

        app(DeleteTenantUseCase::class)->execute($tenant->id);

        self::assertFalse(Cache::has('facturador:tenant:id:'.$tenant->id));
        self::assertFalse(Cache::has('facturador:tenant:ruc:'.$tenant->ruc));
        self::assertFalse(Cache::has('facturador:tenant:external:'.$tenant->external_tenant_id));
    }

    public function test_tenant_context_is_cleared_even_when_request_fails(): void
    {
        $context = app(TenantContext::class);
        $context->set(new TenantIdentity(
            tenantId: 1,
            ruc: '20600000004',
            schema: 'tenant_context',
            sunatMode: 'disabled',
            countryCode: 'PE',
            documentMode: 'ticket_only',
            fiscalStatus: 'not_configured',
        ));

        try {
            app(SetTenantSearchPathMiddleware::class)->handle(
                Request::create('/api/test', 'GET'),
                static function (): never {
                    throw new \RuntimeException('expected failure');
                },
            );
            self::fail('The test callback should fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('expected failure', $exception->getMessage());
        }

        self::assertNull($context->get());
    }

    private function tenant(string $ruc, string $schema, string $externalId): Tenant
    {
        return Tenant::query()->create([
            'ruc' => $ruc,
            'business_name' => $schema,
            'schema_name' => $schema,
            'sunat_mode' => Tenant::SUNAT_MODE_DISABLED,
            'external_tenant_id' => $externalId,
            'country_code' => 'PE',
            'tax_id' => $ruc,
            'document_mode' => Tenant::DOCUMENT_MODE_TICKET_ONLY,
            'fiscal_status' => Tenant::FISCAL_STATUS_NOT_CONFIGURED,
            'is_active' => true,
        ]);
    }
}
