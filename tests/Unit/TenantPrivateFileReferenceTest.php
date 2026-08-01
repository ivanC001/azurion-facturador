<?php

namespace Tests\Unit;

use App\Support\Tenants\TenantPrivateFileReference;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class TenantPrivateFileReferenceTest extends TestCase
{
    public function test_it_only_accepts_files_inside_the_current_tenant_directory(): void
    {
        Storage::fake('tenants');
        Storage::disk('tenants')->put('20123456789/logos/logo.png', 'image');

        $this->assertTrue(TenantPrivateFileReference::isAvailable(
            '20123456789',
            'logos',
            '20123456789/logos/logo.png',
        ));
        $this->assertFalse(TenantPrivateFileReference::isAvailable(
            '20123456789',
            'logos',
            '20999999999/logos/logo.png',
        ));
        $this->assertNull(TenantPrivateFileReference::safeKey(
            '20123456789',
            'certificados',
            'C:\\Windows\\win.ini',
        ));
        $this->assertNull(TenantPrivateFileReference::safeKey(
            '20123456789',
            'certificados',
            '20123456789/certificados/../../otro.pem',
        ));
    }

    public function test_test_certificate_cannot_be_used_as_a_production_certificate(): void
    {
        Storage::fake('tenants');
        $testCertificate = (string) file_get_contents(storage_path('certificates/ejemplo123456789.pem'));
        Storage::disk('tenants')->put('20123456789/certificados/renamed.pem', $testCertificate);
        Storage::disk('tenants')->put('20123456789/certificados/real.pem', 'different-certificate');

        $this->assertFalse(TenantPrivateFileReference::isProductionCertificateAvailable(
            '20123456789',
            '20123456789/certificados/renamed.pem',
        ));
        $this->assertTrue(TenantPrivateFileReference::isProductionCertificateAvailable(
            '20123456789',
            '20123456789/certificados/real.pem',
        ));
    }
}
