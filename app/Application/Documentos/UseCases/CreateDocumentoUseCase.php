<?php

namespace App\Application\Documentos\UseCases;

use App\Application\Documentos\DTOs\DocumentoPayloadData;
use App\Domain\Documentos\Contracts\DocumentoRepository;
use App\Domain\Documentos\Events\DocumentoRecibido;
use App\Domain\Pdf\Contracts\DocumentPdfGenerator;
use App\Application\Sunat\UseCases\DispatchSunatEnvioUseCase;
use App\Infrastructure\Pdf\TenantBillingLogoResolver;
use App\Infrastructure\Tenant\TenantStoragePathResolver;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

final class CreateDocumentoUseCase
{
    public function __construct(
        private readonly DocumentoRepository $documentoRepository,
        private readonly DispatchSunatEnvioUseCase $dispatchSunatEnvioUseCase,
        private readonly DocumentPdfGenerator $documentPdfGenerator,
        private readonly TenantStoragePathResolver $tenantStoragePathResolver,
        private readonly TenantBillingLogoResolver $tenantBillingLogoResolver,
    ) {
    }

    public function execute(DocumentoPayloadData $payload): array
    {
        $documento = $this->documentoRepository->create($payload->toArray());
        $sendToSunat = $documento->tipo_documento !== 'TK';
        if (! $documento->wasRecentlyCreated) {
            return $this->responseFor($documento, $sendToSunat);
        }

        // Electronic documents generate their definitive PDF in the SUNAT
        // worker. Keeping that CPU-heavy work out of the HTTP request avoids
        // blocking PHP workers under load. Tickets have no SUNAT job, so they
        // still need their PDF synchronously.
        if (! $sendToSunat) {
            $this->generateInitialPdf($documento, false);
        }

        DocumentoRecibido::dispatch($documento->id);

        if ($sendToSunat) {
            $this->dispatchSunatEnvioUseCase->execute($documento->id);
        }

        return $this->responseFor($documento, $sendToSunat);
    }

    /**
     * @return array<string, mixed>
     */
    private function responseFor(\App\Models\Documento $documento, bool $sendToSunat): array
    {
        $ruc = (string) data_get($documento->empresa, 'ruc', data_get($documento->payload, 'empresa.ruc', '00000000000'));

        return [
            'documento_id' => $documento->id,
            'serie' => $documento->serie,
            'correlativo' => $documento->correlativo,
            'numero_documento' => $this->documentNumber($documento),
            'estado' => $documento->estado,
            'sunat_async' => $sendToSunat,
            'pdf_url' => $this->signedArtifactUrl('documentos.pdf', $documento->id, $ruc),
            'xml_url' => $sendToSunat ? $this->signedArtifactUrl('documentos.xml', $documento->id, $ruc) : null,
            'cdr_url' => $sendToSunat ? $this->signedArtifactUrl('documentos.cdr', $documento->id, $ruc) : null,
        ];
    }

    private function documentNumber(\App\Models\Documento $documento): string
    {
        $correlativo = is_numeric($documento->correlativo)
            ? str_pad((string) $documento->correlativo, 8, '0', STR_PAD_LEFT)
            : (string) $documento->correlativo;

        return sprintf('%s-%s', (string) $documento->serie, $correlativo);
    }

    private function signedArtifactUrl(string $routeName, int $documentId, string $ruc): string
    {
        return URL::temporarySignedRoute(
            $routeName,
            now()->addMinutes(30),
            ['id' => $documentId, 'tenant_ruc' => $ruc],
        );
    }

    private function generateInitialPdf(\App\Models\Documento $documento, bool $sendToSunat): void
    {
        $payload = is_array($documento->payload) ? $documento->payload : [];

        $documentoPayload = (array) data_get($payload, 'documento', []);
        $documentoPayload['tipo'] = (string) $documento->tipo_documento;
        $documentoPayload['serie'] = (string) $documento->serie;
        $documentoPayload['correlativo'] = (string) $documento->correlativo;

        $pdfContext = [
            'estado' => $sendToSunat ? 'RECIBIDO' : 'REGISTRADO',
            'hash' => $documento->hash,
            'mensaje' => $sendToSunat
                ? 'Documento registrado. Facturacion en cola para SUNAT.'
                : 'Ticket de venta registrado en facturador.',
            'empresa' => $this->tenantBillingLogoResolver->resolve((array) data_get($payload, 'empresa', [])),
            'cliente' => (array) data_get($payload, 'cliente', []),
            'documento' => $documentoPayload,
            'detalles' => (array) data_get($payload, 'detalles', []),
        ];

        $pdfBinary = $this->documentPdfGenerator->generate($pdfContext);
        $baseName = $this->buildBaseName($documento);
        Storage::disk(config('facturador.storage.disk', 'tenants'))->put($this->tenantStoragePathResolver->pdfPath($baseName.'.pdf'), $pdfBinary);
    }

    private function buildBaseName(\App\Models\Documento $documento): string
    {
        $ruc = (string) data_get($documento->empresa, 'ruc', data_get($documento->payload, 'empresa.ruc', '00000000000'));

        return sprintf('%s-%s-%s-%s', $ruc, $documento->tipo_documento, $documento->serie, $documento->correlativo);
    }

}
