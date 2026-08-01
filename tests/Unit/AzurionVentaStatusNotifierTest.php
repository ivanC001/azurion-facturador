<?php

namespace Tests\Unit;

use App\Application\Integrations\Azurion\AzurionVentaStatusNotifier;
use App\Models\Documento;
use App\Models\DocumentoSunat;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class AzurionVentaStatusNotifierTest extends TestCase
{
    public function test_callback_contains_temporary_signed_artifact_urls(): void
    {
        config()->set('facturador.integrations.azurion.enabled', true);
        config()->set('facturador.integrations.azurion.callback_url', 'http://azurion.test/api/v1/facturador/callback/ventas');
        config()->set('facturador.integrations.azurion.callback_urls.ventas', 'http://azurion.test/api/v1/facturador/callback/ventas');
        config()->set('facturador.integrations.azurion.api_key', 'callback-key');
        config()->set('facturador.integrations.azurion.shared_secret', 'callback-secret');

        Http::fake([
            'http://azurion.test/*' => Http::response(['success' => true], 200),
        ]);

        $documento = new Documento([
            'tipo_documento' => '03',
            'external_id' => 'VENTA-10',
            'serie' => 'B001',
            'correlativo' => '10',
            'estado' => 'ACEPTADO',
            'payload' => [
                'empresa' => ['ruc' => '20000000001'],
                'documento' => ['external_id' => 'VENTA-10'],
            ],
            'empresa' => ['ruc' => '20000000001'],
        ]);
        $documento->id = 10;
        $documento->exists = true;
        $documento->setRelation('sunat', new DocumentoSunat([
            'estado' => 'ACEPTADO',
            'codigo_error' => '0',
            'mensaje' => 'Aceptado',
        ]));

        self::assertTrue(app(AzurionVentaStatusNotifier::class)->notify($documento));

        Http::assertSent(function (HttpClientRequest $request): bool {
            $payload = $request->data();
            $pdfUrl = (string) ($payload['pdfUrl'] ?? '');

            self::assertNotSame('', $pdfUrl);
            $signedRequest = Request::create($pdfUrl, 'GET');
            self::assertSame('20000000001', $signedRequest->query('tenant_ruc'));
            self::assertTrue(URL::hasValidSignature($signedRequest));

            return true;
        });
    }
}
