<?php

namespace App\Application\Documentos\UseCases;

use App\Application\Documentos\DTOs\DocumentoPayloadData;
use App\Application\Integrations\Azurion\AzurionVentaStatusNotifier;
use App\Domain\Documentos\Contracts\DocumentoRepository;
use App\Domain\Documentos\Events\DocumentoRecibido;
use App\Domain\Pdf\Contracts\DocumentPdfGenerator;
use App\Application\Sunat\UseCases\DispatchSunatEnvioUseCase;
use App\Infrastructure\Tenant\TenantStoragePathResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class CreateDocumentoUseCase
{
    public function __construct(
        private readonly DocumentoRepository $documentoRepository,
        private readonly DispatchSunatEnvioUseCase $dispatchSunatEnvioUseCase,
        private readonly AzurionVentaStatusNotifier $azurionVentaStatusNotifier,
        private readonly DocumentPdfGenerator $documentPdfGenerator,
        private readonly TenantStoragePathResolver $tenantStoragePathResolver,
    ) {
    }

    public function execute(DocumentoPayloadData $payload): array
    {
        $documento = $this->documentoRepository->create($payload->toArray());
        $sendToSunat = $documento->tipo_documento !== 'TK';
        if (! $documento->wasRecentlyCreated) {
            return $this->responseFor($documento, $sendToSunat);
        }

        $this->generateInitialPdf($documento, $sendToSunat);

        DocumentoRecibido::dispatch($documento->id);

        if ($sendToSunat) {
            $this->dispatchSunatEnvioUseCase->execute($documento->id);
        }

        $this->azurionVentaStatusNotifier->notify($documento);

        return $this->responseFor($documento, $sendToSunat);
    }

    /**
     * @return array<string, mixed>
     */
    private function responseFor(\App\Models\Documento $documento, bool $sendToSunat): array
    {
        $base = rtrim((string) config('app.url'), '/');
        $ruc = (string) data_get($documento->empresa, 'ruc', data_get($documento->payload, 'empresa.ruc', '00000000000'));
        $query = '?tenant_ruc='.urlencode($ruc);

        return [
            'documento_id' => $documento->id,
            'estado' => $documento->estado,
            'sunat_async' => $sendToSunat,
            'pdf_url' => $base.'/api/documentos/'.$documento->id.'/pdf'.$query,
            'xml_url' => $sendToSunat ? $base.'/api/documentos/'.$documento->id.'/xml'.$query : null,
            'cdr_url' => $sendToSunat ? $base.'/api/documentos/'.$documento->id.'/cdr'.$query : null,
        ];
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
            'empresa' => $this->withTenantBillingLogo((array) data_get($payload, 'empresa', [])),
            'cliente' => (array) data_get($payload, 'cliente', []),
            'documento' => $documentoPayload,
            'detalles' => (array) data_get($payload, 'detalles', []),
        ];

        $pdfBinary = $this->documentPdfGenerator->generate($pdfContext);
        $baseName = $this->buildBaseName($documento);
        Storage::disk('tenants')->put($this->tenantStoragePathResolver->pdfPath($baseName.'.pdf'), $pdfBinary);
    }

    private function buildBaseName(\App\Models\Documento $documento): string
    {
        $ruc = (string) data_get($documento->empresa, 'ruc', data_get($documento->payload, 'empresa.ruc', '00000000000'));

        return sprintf('%s-%s-%s-%s', $ruc, $documento->tipo_documento, $documento->serie, $documento->correlativo);
    }

    /**
     * @param array<string, mixed> $empresa
     * @return array<string, mixed>
     */
    private function withTenantBillingLogo(array $empresa): array
    {
        if (trim((string) ($empresa['logo_pdf_url'] ?? $empresa['logo_url'] ?? '')) !== '') {
            return $empresa;
        }

        try {
            $logo = DB::table('configuracion_facturacion')->orderBy('id')->value('logo_pdf_url');
        } catch (\Throwable) {
            $logo = null;
        }

        if (is_string($logo) && trim($logo) !== '') {
            $empresa['logo_pdf_url'] = trim($logo);
        }

        return $empresa;
    }
}
