<?php

namespace Tests\Unit;

use App\Application\Integrations\Azurion\AzurionVentaStatusNotifier;
use App\Domain\Documentos\Contracts\DocumentoRepository;
use App\Domain\Sunat\Contracts\SunatSender;
use App\Infrastructure\Tenant\TenantStoragePathResolver;
use App\Jobs\ProcessSunatBetaDocumentJob;
use App\Models\Documento;
use App\Models\Tenant;
use App\Support\Tenants\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use RuntimeException;
use Tests\TestCase;

final class SunatJobTenantIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_timeout_is_lower_than_redis_retry_window_and_has_a_document_lock(): void
    {
        config()->set('queue.connections.redis.retry_after', 180);
        $job = new ProcessSunatBetaDocumentJob(
            documentoId: 99,
            tenantId: 7,
            tenantRuc: '20612345678',
            tenantSchema: 'tenant_job_test',
        );

        $this->assertSame(120, $job->timeout);
        $this->assertSame([10, 30, 60, 120], $job->backoff());
        $this->assertGreaterThan($job->timeout, config('queue.connections.redis.retry_after'));
        $this->assertContainsOnlyInstancesOf(WithoutOverlapping::class, $job->middleware());
    }

    public function test_job_restores_the_complete_current_tenant_identity(): void
    {
        $tenant = Tenant::query()->create([
            'ruc' => '20612345678',
            'business_name' => 'Empresa de prueba',
            'schema_name' => 'tenant_job_test',
            'sunat_mode' => Tenant::SUNAT_MODE_BETA,
            'country_code' => 'PE',
            'tax_id' => '20612345678',
            'document_mode' => Tenant::DOCUMENT_MODE_ELECTRONIC,
            'fiscal_status' => Tenant::FISCAL_STATUS_ACTIVE,
            'is_active' => true,
        ]);
        $context = new TenantContext();
        $assertIdentity = function () use ($context, $tenant): void {
            $identity = $context->required();
            $this->assertSame($tenant->id, $identity->tenantId);
            $this->assertSame('PE', $identity->countryCode);
            $this->assertSame(Tenant::DOCUMENT_MODE_ELECTRONIC, $identity->documentMode);
            $this->assertSame(Tenant::FISCAL_STATUS_ACTIVE, $identity->fiscalStatus);

            throw new RuntimeException('stop-after-identity');
        };

        $job = new ProcessSunatBetaDocumentJob(
            documentoId: 99,
            tenantId: $tenant->id,
            tenantRuc: $tenant->ruc,
            tenantSchema: $tenant->schema_name,
        );

        try {
            $job->handle(
                new IdentityInspectingDocumentoRepository($assertIdentity),
                new NoopSunatSender(),
                new TenantStoragePathResolver($context),
                $context,
                new AzurionVentaStatusNotifier(),
            );
            $this->fail('The inspecting repository must stop the job.');
        } catch (RuntimeException $exception) {
            $this->assertSame('stop-after-identity', $exception->getMessage());
        }

        $this->assertNull($context->get());
    }
}

final class IdentityInspectingDocumentoRepository implements DocumentoRepository
{
    public function __construct(private readonly Closure $assertIdentity)
    {
    }

    public function create(array $payload): Documento
    {
        throw new RuntimeException('Not used.');
    }

    public function findOrFail(int $id): Documento
    {
        ($this->assertIdentity)();

        throw new RuntimeException('Not used.');
    }

    public function markProcessing(Documento $documento): void
    {
    }

    public function markResult(Documento $documento, string $estado, ?string $ticket = null, ?string $hash = null): void
    {
    }
}

final class NoopSunatSender implements SunatSender
{
    public function send(Documento $documento): array
    {
        throw new RuntimeException('Not used.');
    }
}
