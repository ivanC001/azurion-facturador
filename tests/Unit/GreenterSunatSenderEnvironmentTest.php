<?php

namespace Tests\Unit;

use App\Domain\Pdf\Contracts\DocumentPdfGenerator;
use App\Infrastructure\Sunat\GreenterSunatSender;
use App\Infrastructure\Tenant\TenantSchemaManager;
use App\Support\Tenants\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

final class GreenterSunatSenderEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_beta_always_uses_official_test_identity_certificate_and_endpoint(): void
    {
        $schema = 'tenant_beta_test';
        $this->app->make(TenantSchemaManager::class)->provision($schema);

        DB::table($schema.'.configuracion_facturacion')->insert([
            'id' => 1,
            'ruc_sol' => '20612345678',
            'usuario_sol' => 'USUARIO_EQUIVOCADO',
            'clave_sol_encrypted' => Crypt::encryptString('clave-equivocada'),
            'certificado_url' => '20612345678/certificados/certificado-equivocado.pem',
            'modo_sunat' => 'beta',
        ]);

        $sender = new GreenterSunatSender(
            new TenantContext(),
            new class implements DocumentPdfGenerator {
                public function generate(array $context): string
                {
                    return '';
                }
            },
        );

        $method = new ReflectionMethod($sender, 'resolveConfig');
        $config = $method->invoke($sender, [], '20612345678', 'beta', '03');

        $this->assertSame('beta', $config['mode']);
        $this->assertSame('https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService', $config['service']);
        $this->assertSame('20000000001', $config['sol_ruc']);
        $this->assertSame('MODDATOS', $config['sol_user']);
        $this->assertSame('moddatos', $config['sol_password']);
        $this->assertTrue($config['uses_test_credentials']);
        $this->assertSame(
            file_get_contents(storage_path('certificates/ejemplo123456789.pem')),
            $config['certificate_pem'],
        );
    }
}
