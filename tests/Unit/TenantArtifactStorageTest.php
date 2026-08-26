<?php

namespace Tests\Unit;

use App\Infrastructure\Tenant\TenantArtifactStorage;
use App\Infrastructure\Tenant\TenantStoragePathResolver;
use App\Support\Tenants\TenantContext;
use App\Support\Tenants\TenantIdentity;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class TenantArtifactStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('tenants');
        config()->set('facturador.storage.disk', 'tenants');
    }

    public function test_paths_do_not_repeat_the_disk_root(): void
    {
        $resolver = new TenantStoragePathResolver($this->context());

        // El disco ya apunta a storage/app/tenants: repetir el prefijo era lo
        // que partia los archivos del tenant en dos arboles distintos.
        self::assertSame('20600000001/pdf/doc.pdf', $resolver->pdfPath('doc.pdf'));
        self::assertSame('20600000001/xml/doc.xml', $resolver->xmlPath('doc.xml'));
        self::assertSame('20600000001/cdr/R-doc.zip', $resolver->cdrPath('R-doc.zip'));
        self::assertSame('20600000001/certificados/cert.pem', $resolver->certPath('cert.pem'));
    }

    public function test_it_still_reads_files_left_in_the_old_location(): void
    {
        Storage::disk('tenants')->put('tenants/20600000001/pdf/doc.pdf', 'contenido-antiguo');

        $storage = new TenantArtifactStorage;

        self::assertTrue($storage->exists('20600000001/pdf/doc.pdf'));
        self::assertSame('contenido-antiguo', $storage->get('20600000001/pdf/doc.pdf'));
        self::assertSame(
            'tenants/20600000001/pdf/doc.pdf',
            $storage->readablePath('20600000001/pdf/doc.pdf'),
        );
    }

    public function test_the_canonical_copy_wins_over_the_legacy_one(): void
    {
        Storage::disk('tenants')->put('tenants/20600000001/pdf/doc.pdf', 'antiguo');
        Storage::disk('tenants')->put('20600000001/pdf/doc.pdf', 'vigente');

        self::assertSame('vigente', (new TenantArtifactStorage)->get('20600000001/pdf/doc.pdf'));
    }

    public function test_writes_always_land_on_the_canonical_path(): void
    {
        (new TenantArtifactStorage)->put('20600000001/pdf/doc.pdf', 'nuevo');

        Storage::disk('tenants')->assertExists('20600000001/pdf/doc.pdf');
        Storage::disk('tenants')->assertMissing('tenants/20600000001/pdf/doc.pdf');
    }

    public function test_missing_files_report_no_readable_path(): void
    {
        $storage = new TenantArtifactStorage;

        self::assertFalse($storage->exists('20600000001/pdf/ausente.pdf'));
        self::assertNull($storage->readablePath('20600000001/pdf/ausente.pdf'));
        self::assertNull($storage->get('20600000001/pdf/ausente.pdf'));
    }

    public function test_the_normalize_command_moves_legacy_files_without_overwriting(): void
    {
        Storage::disk('tenants')->put('tenants/20600000001/pdf/movible.pdf', 'antiguo');
        Storage::disk('tenants')->put('tenants/20600000001/pdf/duplicado.pdf', 'antiguo');
        Storage::disk('tenants')->put('20600000001/pdf/duplicado.pdf', 'vigente');

        $this->artisan('facturador:storage:normalizar', ['--force' => true])
            ->assertSuccessful();

        Storage::disk('tenants')->assertExists('20600000001/pdf/movible.pdf');
        Storage::disk('tenants')->assertMissing('tenants/20600000001/pdf/movible.pdf');

        // El comprobante que ya estaba en la ruta canonica no se toca.
        self::assertSame('vigente', Storage::disk('tenants')->get('20600000001/pdf/duplicado.pdf'));
    }

    private function context(): TenantContext
    {
        $context = new TenantContext;
        $context->set(new TenantIdentity(
            tenantId: 1,
            ruc: '20600000001',
            schema: 'tenant_demo',
            sunatMode: 'beta',
            countryCode: 'PE',
            documentMode: 'electronic',
            fiscalStatus: 'active',
        ));

        return $context;
    }
}
