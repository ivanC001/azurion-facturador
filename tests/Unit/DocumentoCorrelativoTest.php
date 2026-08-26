<?php

namespace Tests\Unit;

use App\Domain\Documentos\Contracts\DocumentoRepository;
use App\Domain\Documentos\Exceptions\CorrelativoAgotadoException;
use App\Support\Tenants\TenantContext;
use App\Support\Tenants\TenantIdentity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DocumentoCorrelativoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTenantTables();

        app(TenantContext::class)->set(new TenantIdentity(
            tenantId: 1,
            ruc: '20600000001',
            schema: 'tenant_correlativos',
            sunatMode: 'beta',
            countryCode: 'PE',
            documentMode: 'electronic',
            fiscalStatus: 'active',
        ));
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    public function test_first_document_of_a_branch_series_starts_at_one(): void
    {
        $this->sucursal('SUC01', 1);
        $this->serie('SUC01', '01', 'F001', correlativoActual: 0);

        $documento = $this->repository()->create($this->payload('01', sucursalCodigo: 'SUC01'));

        self::assertSame('F001', $documento->serie);
        self::assertSame('1', $documento->correlativo);
    }

    public function test_first_document_without_branch_starts_at_one(): void
    {
        $documento = $this->repository()->create($this->payload('01'));

        self::assertSame('F001', $documento->serie);
        self::assertSame('1', $documento->correlativo);
    }

    public function test_consecutive_documents_increment_without_gaps(): void
    {
        $this->sucursal('SUC01', 1);
        $this->serie('SUC01', '03', 'B001', correlativoActual: 0);

        $emitted = [];
        for ($i = 0; $i < 5; $i++) {
            $emitted[] = $this->repository()->create($this->payload('03', sucursalCodigo: 'SUC01'))->correlativo;
        }

        self::assertSame(['1', '2', '3', '4', '5'], $emitted);
        self::assertSame(5, (int) DB::table('series')->where('serie', 'B001')->value('correlativo_actual'));
    }

    public function test_numbering_resumes_from_the_last_emitted_document(): void
    {
        $this->sucursal('SUC01', 1);
        $this->serie('SUC01', '01', 'F001', correlativoActual: 0);
        $this->existingDocumento('01', 'F001', '40');

        $documento = $this->repository()->create($this->payload('01', sucursalCodigo: 'SUC01'));

        self::assertSame('41', $documento->correlativo);
    }

    public function test_stored_counter_wins_when_it_is_ahead_of_the_emitted_documents(): void
    {
        $this->sucursal('SUC01', 1);
        $this->serie('SUC01', '01', 'F001', correlativoActual: 120);

        $documento = $this->repository()->create($this->payload('01', sucursalCodigo: 'SUC01'));

        self::assertSame('121', $documento->correlativo);
    }

    public function test_exhausted_series_fails_instead_of_restarting_at_one(): void
    {
        $this->sucursal('SUC01', 1);
        $this->serie('SUC01', '01', 'F001', correlativoActual: 99_999_999);

        $this->expectException(CorrelativoAgotadoException::class);

        $this->repository()->create($this->payload('01', sucursalCodigo: 'SUC01'));
    }

    public function test_exhausted_series_reports_an_actionable_client_error(): void
    {
        $exception = new CorrelativoAgotadoException('01', 'F001', 100_000_000, 99_999_999);

        self::assertSame(422, $exception->getStatusCode());
        self::assertStringContainsString('F001', $exception->getMessage());
    }

    public function test_same_external_id_reuses_the_document_instead_of_burning_a_correlativo(): void
    {
        $first = $this->repository()->create($this->payload('01', externalId: 'VENTA-1'));
        $second = $this->repository()->create($this->payload('01', externalId: 'VENTA-1'));

        self::assertSame($first->id, $second->id);
        self::assertSame('1', $second->correlativo);
        self::assertSame(1, DB::table('documentos')->count());
    }

    public function test_non_numeric_legacy_correlativos_do_not_break_numbering(): void
    {
        $this->existingDocumento('01', 'F001', 'ANULADO');
        $this->existingDocumento('01', 'F001', '7');

        $documento = $this->repository()->create($this->payload('01'));

        self::assertSame('8', $documento->correlativo);
    }

    private function repository(): DocumentoRepository
    {
        return app(DocumentoRepository::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $tipo, string $sucursalCodigo = '', string $externalId = ''): array
    {
        return [
            'empresa' => ['ruc' => '20600000001', 'razon_social' => 'EMPRESA DEMO'],
            'cliente' => ['tipo_doc' => '6', 'num_doc' => '20600000002', 'razon_social' => 'CLIENTE DEMO'],
            'documento' => array_filter([
                'tipo' => $tipo,
                'external_id' => $externalId !== '' ? $externalId : null,
            ]),
            'detalles' => [['descripcion' => 'Item', 'cantidad' => 1, 'total' => 118.0]],
            'sucursal' => $sucursalCodigo !== '' ? ['codigo' => $sucursalCodigo] : [],
        ];
    }

    private function sucursal(string $codigo, int $numero): void
    {
        DB::table('sucursales')->insert([
            'codigo' => $codigo,
            'numero' => $numero,
            'nombre' => 'Sucursal '.$codigo,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function serie(string $sucursalCodigo, string $tipoDocumento, string $serie, int $correlativoActual): void
    {
        DB::table('series')->insert([
            'sucursal_id' => DB::table('sucursales')->where('codigo', $sucursalCodigo)->value('id'),
            'sucursal_codigo' => $sucursalCodigo,
            'tipo_documento' => $tipoDocumento,
            'serie' => $serie,
            'correlativo_actual' => $correlativoActual,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function existingDocumento(string $tipoDocumento, string $serie, string $correlativo): void
    {
        DB::table('documentos')->insert([
            'tipo_documento' => $tipoDocumento,
            'serie' => $serie,
            'correlativo' => $correlativo,
            'estado' => 'ACEPTADO',
            'payload' => '{}',
            'empresa' => '{}',
            'cliente' => '{}',
            'sucursal' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTenantTables(): void
    {
        Schema::create('documentos', function (Blueprint $table): void {
            $table->id();
            $table->string('tipo_documento', 3)->nullable();
            $table->string('external_id', 120)->nullable();
            $table->string('serie', 10)->nullable();
            $table->string('correlativo', 20)->nullable();
            $table->string('estado', 25)->nullable();
            $table->text('payload')->nullable();
            $table->text('empresa')->nullable();
            $table->text('cliente')->nullable();
            $table->text('sucursal')->nullable();
            $table->string('hash', 128)->nullable();
            $table->string('ticket', 80)->nullable();
            $table->unsignedBigInteger('submitted_by_user_id')->nullable();
            $table->string('submitted_by_email')->nullable();
            $table->unsignedBigInteger('submitted_by_api_client_id')->nullable();
            $table->string('submitted_by_auth_mode', 20)->nullable();
            $table->timestamps();
            $table->unique(['tipo_documento', 'serie', 'correlativo']);
        });

        Schema::create('sucursales', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->integer('numero')->nullable();
            $table->string('nombre', 180)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('series', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sucursal_id')->nullable();
            $table->string('sucursal_codigo', 30)->nullable();
            $table->string('tipo_documento', 3)->nullable();
            $table->string('serie', 10)->nullable();
            $table->bigInteger('correlativo_actual')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['sucursal_codigo', 'tipo_documento']);
        });
    }
}
