<?php

namespace App\Http\Controllers\Api;

use App\Application\Documentos\DTOs\DocumentoPayloadData;
use App\Application\Documentos\Services\DocumentoTaxPayloadValidator;
use App\Application\Documentos\UseCases\CreateDocumentoUseCase;
use App\Application\Documentos\UseCases\GetDocumentoUseCase;
use App\Application\Documentos\UseCases\ListDocumentosUseCase;
use App\Domain\Pdf\Contracts\DocumentPdfGenerator;
use App\Support\Ubigeos\UbigeoCatalog;
use App\Infrastructure\Tenant\TenantStoragePathResolver;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentoController
{
    public function __construct(
        private readonly CreateDocumentoUseCase $createDocumentoUseCase,
        private readonly GetDocumentoUseCase $getDocumentoUseCase,
        private readonly ListDocumentosUseCase $listDocumentosUseCase,
        private readonly DocumentPdfGenerator $documentPdfGenerator,
        private readonly TenantStoragePathResolver $storagePathResolver,
        private readonly UbigeoCatalog $ubigeoCatalog,
        private readonly DocumentoTaxPayloadValidator $documentTaxPayloadValidator,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'external_ids' => ['nullable', 'string', 'max:8000'],
            'q' => ['nullable', 'string', 'max:120'],
            'estado' => ['nullable', 'string', 'max:30'],
            'tipo_documento' => ['nullable', 'string', 'max:5'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'include_artifacts' => ['nullable', 'boolean'],
        ]);

        $externalIds = collect(explode(',', (string) ($data['external_ids'] ?? '')))
            ->map(fn (string $value): string => trim($value))
            ->filter(fn (string $value): bool => $value !== '')
            ->values()
            ->all();

        $result = $this->listDocumentosUseCase->execute([
            'external_ids' => $externalIds,
            'q' => $data['q'] ?? null,
            'estado' => $data['estado'] ?? null,
            'tipo_documento' => $data['tipo_documento'] ?? null,
            'limit' => (int) ($data['limit'] ?? 100),
        ]);

        $base = rtrim(config('app.url'), '/');
        $includeArtifacts = (bool) ($data['include_artifacts'] ?? false);
        $items = array_map(function (array $item) use ($base, $includeArtifacts): array {
            $id = (int) ($item['id'] ?? 0);
            $ruc = (string) ($item['empresa_ruc'] ?? '00000000000');
            $tipo = (string) ($item['tipo_documento'] ?? '01');
            $serie = (string) ($item['serie'] ?? 'F001');
            $correlativo = (string) ($item['correlativo'] ?? '1');
            $baseName = sprintf('%s-%s-%s-%s', $ruc, $tipo, $serie, $correlativo);
            $isTicket = strtoupper($tipo) === 'TK';
            $query = '?tenant_ruc='.urlencode($ruc);

            if (! $includeArtifacts) {
                return array_merge($item, [
                    'pdf_url' => $id > 0 ? $base.'/api/documentos/'.$id.'/pdf'.$query : null,
                    'xml_url' => ($id > 0 && ! $isTicket) ? $base.'/api/documentos/'.$id.'/xml'.$query : null,
                    'cdr_url' => ($id > 0 && ! $isTicket) ? $base.'/api/documentos/'.$id.'/cdr'.$query : null,
                    'has_pdf' => null,
                    'has_xml' => null,
                    'has_cdr' => null,
                ]);
            }

            $pdfExists = Storage::disk('tenants')->exists($this->storagePathResolver->pdfPath($baseName.'.pdf'));
            $xmlExists = ! $isTicket && Storage::disk('tenants')->exists($this->storagePathResolver->xmlPath($baseName.'.xml'));
            $cdrExists = ! $isTicket && Storage::disk('tenants')->exists($this->storagePathResolver->cdrPath('R-'.$baseName.'.zip'));

            return array_merge($item, [
                'pdf_url' => ($id > 0 && $pdfExists) ? $base.'/api/documentos/'.$id.'/pdf'.$query : null,
                'xml_url' => ($id > 0 && $xmlExists) ? $base.'/api/documentos/'.$id.'/xml'.$query : null,
                'cdr_url' => ($id > 0 && $cdrExists) ? $base.'/api/documentos/'.$id.'/cdr'.$query : null,
                'has_pdf' => $pdfExists,
                'has_xml' => $xmlExists,
                'has_cdr' => $cdrExists,
            ]);
        }, $result['items']);

        return ApiResponse::success([
            'total' => (int) ($result['total'] ?? 0),
            'items' => $items,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->createFromRequest($request, '01');
    }

    public function storeBoleta(Request $request): JsonResponse
    {
        return $this->createFromRequest($request, '03');
    }

    public function storeTicket(Request $request): JsonResponse
    {
        return $this->createFromRequest($request, 'TK');
    }

    public function storeNotaCredito(Request $request): JsonResponse
    {
        return $this->createFromRequest($request, '07');
    }

    public function storeNotaDebito(Request $request): JsonResponse
    {
        return $this->createFromRequest($request, '08');
    }

    public function storeGuia(Request $request): JsonResponse
    {
        return $this->createFromRequest($request, '09');
    }

    public function storeResumen(Request $request): JsonResponse
    {
        return $this->createFromRequest($request, 'RC');
    }

    public function show(int $id): JsonResponse
    {
        $documento = $this->getDocumentoUseCase->execute($id);
        $baseName = sprintf(
            '%s-%s-%s-%s',
            data_get($documento, 'payload.empresa.ruc', '00000000000'),
            $documento['tipo_documento'],
            $documento['serie'],
            $documento['correlativo'],
        );
        $isTicket = strtoupper((string) $documento['tipo_documento']) === 'TK';
        $xmlExists = ! $isTicket && Storage::disk('tenants')->exists($this->storagePathResolver->xmlPath($baseName.'.xml'));
        $pdfExists = Storage::disk('tenants')->exists($this->storagePathResolver->pdfPath($baseName.'.pdf'));
        $cdrExists = ! $isTicket && Storage::disk('tenants')->exists($this->storagePathResolver->cdrPath('R-'.$baseName.'.zip'));

        $base = rtrim(config('app.url'), '/');
        $ruc = (string) data_get($documento, 'payload.empresa.ruc', '00000000000');
        $query = '?tenant_ruc='.urlencode($ruc);

        return ApiResponse::success(array_merge($documento, [
            'pdf_url' => $base.'/api/documentos/'.$id.'/pdf'.$query,
            'xml_url' => $isTicket ? null : $base.'/api/documentos/'.$id.'/xml'.$query,
            'cdr_url' => $isTicket ? null : $base.'/api/documentos/'.$id.'/cdr'.$query,
            'has_pdf' => $pdfExists,
            'has_xml' => $xmlExists,
            'has_cdr' => $cdrExists,
        ]));
    }

    public function pdf(int $id): StreamedResponse
    {
        return $this->download($id, 'pdf', '.pdf', 'application/pdf');
    }

    public function xml(int $id): StreamedResponse
    {
        return $this->download($id, 'xml', '.xml', 'application/xml');
    }

    public function cdr(int $id): StreamedResponse
    {
        return $this->download($id, 'cdr', '.zip', 'application/zip', 'R-');
    }

    private function createFromRequest(Request $request, string $tipo): JsonResponse
    {
        $data = $request->validate([
            'empresa' => ['required', 'array'],
            'cliente' => [$tipo === 'TK' ? 'nullable' : 'required', 'array'],
            'documento' => ['required', 'array'],
            'detalles' => ['required', 'array', 'min:1'],
            'sucursal' => ['nullable', 'array'],
            'sucursal.codigo' => ['nullable', 'string', 'max:30'],
            'sucursal.ubigeo' => ['nullable', 'string', 'size:6'],
            'traslado' => ['nullable', 'array'],
            'traslado.modalidad' => ['nullable', 'string', 'max:2'],
            'traslado.motivo_codigo' => ['nullable', 'string', 'max:2'],
            'traslado.motivo_descripcion' => ['nullable', 'string', 'max:120'],
            'traslado.fecha_inicio' => ['nullable', 'date'],
            'traslado.peso_total' => ['nullable', 'numeric', 'min:0'],
            'traslado.unidad_peso' => ['nullable', 'string', 'max:5'],
            'traslado.numero_bultos' => ['nullable', 'integer', 'min:1'],
            'traslado.partida' => ['nullable', 'array'],
            'traslado.partida.ubigeo' => ['nullable', 'string', 'size:6'],
            'traslado.partida.direccion' => ['nullable', 'string', 'max:255'],
            'traslado.partida.departamento' => ['nullable', 'string', 'max:80'],
            'traslado.partida.provincia' => ['nullable', 'string', 'max:80'],
            'traslado.partida.distrito' => ['nullable', 'string', 'max:80'],
            'traslado.partida.cod_local' => ['nullable', 'string', 'max:10'],
            'traslado.llegada' => ['nullable', 'array'],
            'traslado.llegada.ubigeo' => ['nullable', 'string', 'size:6'],
            'traslado.llegada.direccion' => ['nullable', 'string', 'max:255'],
            'traslado.llegada.departamento' => ['nullable', 'string', 'max:80'],
            'traslado.llegada.provincia' => ['nullable', 'string', 'max:80'],
            'traslado.llegada.distrito' => ['nullable', 'string', 'max:80'],
            'traslado.llegada.cod_local' => ['nullable', 'string', 'max:10'],
        ]);

        $data['cliente'] = $data['cliente'] ?? [];
        $data['documento']['tipo'] = $tipo;
        $data = $this->applySalesDocumentRules($data, $tipo);
        $data = $this->applyFacturaDateRule($data, $tipo);
        $data = $this->normalizeKnownUbigeos($data);
        $data = $this->applyGuiaRules($data, $tipo);
        $data = $this->documentTaxPayloadValidator->validate($data, $tipo);
        $data = $this->attachIssuerMetadata($request, $data);

        $result = $this->createDocumentoUseCase->execute(DocumentoPayloadData::fromArray($data));
        $response = [
            'estado' => $result['estado'],
            'documento_id' => $result['documento_id'],
            'sunat_async' => (bool) ($result['sunat_async'] ?? false),
            'ticket' => null,
            'hash' => null,
            'pdf_url' => $result['pdf_url'] ?? null,
            'xml_url' => $result['xml_url'] ?? null,
            'cdr_url' => $result['cdr_url'] ?? null,
        ];

        return ($result['sunat_async'] ?? false)
            ? ApiResponse::accepted($response)
            : ApiResponse::success($response, 201);
    }

    private function applySalesDocumentRules(array $data, string $tipo): array
    {
        if ($tipo === '01') {
            abort_unless(
                data_get($data, 'cliente.tipo_doc') === '6'
                && preg_match('/^[0-9]{11}$/', (string) data_get($data, 'cliente.num_doc', '')) === 1
                && $this->customerName($data) !== '',
                422,
                'Factura requires cliente.tipo_doc=6, cliente.num_doc RUC de 11 digitos y razon social.',
            );
        }

        if ($tipo !== '03') {
            return $data;
        }

        $total = $this->documentTotal($data);
        if ($total <= 500) {
            if ($this->customerName($data) === '' && (string) data_get($data, 'cliente.num_doc', '') === '') {
                $data['cliente'] = [
                    'tipo_doc' => '0',
                    'num_doc' => '-',
                    'razon_social' => 'CLIENTES VARIOS',
                ];
            }

            return $data;
        }

        abort_unless(
            data_get($data, 'cliente.tipo_doc') === '1'
            && preg_match('/^[0-9]{8}$/', (string) data_get($data, 'cliente.num_doc', '')) === 1
            && $this->customerName($data) !== '',
            422,
            'Boleta mayor a S/ 500 requiere DNI de 8 digitos y nombre del cliente.',
        );

        return $data;
    }

    private function documentTotal(array $data): float
    {
        $documentTotal = data_get($data, 'documento.total');
        if (is_numeric($documentTotal)) {
            return (float) $documentTotal;
        }

        return collect((array) data_get($data, 'detalles', []))
            ->sum(fn (mixed $item): float => is_array($item) && is_numeric($item['total'] ?? null) ? (float) $item['total'] : 0.0);
    }

    private function customerName(array $data): string
    {
        return trim((string) (data_get($data, 'cliente.razon_social') ?? data_get($data, 'cliente.nombre') ?? ''));
    }

    private function applyFacturaDateRule(array $data, string $tipo): array
    {
        if ($tipo !== '01') {
            return $data;
        }

        $timezone = (string) config('facturador.fiscal_timezone', 'America/Lima');
        $raw = data_get($data, 'documento.fecha_emision');

        try {
            $fechaEmision = (is_string($raw) && trim($raw) !== '')
                ? CarbonImmutable::parse($raw, $timezone)
                : CarbonImmutable::now($timezone);
        } catch (\Throwable) {
            abort(422, 'documento.fecha_emision no tiene un formato valido.');
        }

        $today = CarbonImmutable::now($timezone)->startOfDay();
        $minDate = $today->subDays(2);
        $issueDate = $fechaEmision->startOfDay();

        abort_unless(
            $issueDate->betweenIncluded($minDate, $today),
            422,
            sprintf(
                'La fecha de emision de factura debe estar entre %s y %s.',
                $minDate->toDateString(),
                $today->toDateString(),
            ),
        );

        data_set($data, 'documento.fecha_emision', $fechaEmision->toDateString());

        return $data;
    }

    private function normalizeKnownUbigeos(array $data): array
    {
        $empresaUbigeo = data_get($data, 'empresa.direccion.ubigeo');
        if (is_string($empresaUbigeo) && trim($empresaUbigeo) !== '') {
            $normalized = $this->ubigeoCatalog->normalize($empresaUbigeo);
            abort_unless($normalized !== null, 422, 'empresa.direccion.ubigeo no es valido o no existe en el catalogo.');
            data_set($data, 'empresa.direccion.ubigeo', $normalized);
        }

        $sucursalUbigeo = data_get($data, 'sucursal.ubigeo');
        if (is_string($sucursalUbigeo) && trim($sucursalUbigeo) !== '') {
            $normalized = $this->ubigeoCatalog->normalize($sucursalUbigeo);
            abort_unless($normalized !== null, 422, 'sucursal.ubigeo no es valido o no existe en el catalogo.');
            data_set($data, 'sucursal.ubigeo', $normalized);
        }

        foreach (['traslado.partida.ubigeo', 'traslado.llegada.ubigeo'] as $path) {
            $ubigeo = data_get($data, $path);
            if (is_string($ubigeo) && trim($ubigeo) !== '') {
                $normalized = $this->ubigeoCatalog->normalize($ubigeo);
                abort_unless($normalized !== null, 422, $path.' no es valido o no existe en el catalogo.');
                data_set($data, $path, $normalized);
            }
        }

        return $data;
    }

    private function applyGuiaRules(array $data, string $tipo): array
    {
        if ($tipo !== '09') {
            return $data;
        }

        foreach (['traslado.partida.ubigeo', 'traslado.llegada.ubigeo'] as $path) {
            $ubigeo = trim((string) data_get($data, $path, ''));
            abort_unless($ubigeo !== '', 422, $path.' es obligatorio para guias de remision.');
        }

        return $data;
    }

    private function attachIssuerMetadata(Request $request, array $data): array
    {
        $user = $request->user();
        $userEmail = null;
        if (is_object($user) && method_exists($user, 'getAttribute')) {
            $email = $user->getAttribute('email');
            $userEmail = is_scalar($email) ? (string) $email : null;
        }

        data_set($data, 'meta.submitted_by', [
            'auth_mode' => $request->attributes->get('auth_mode'),
            'user_id' => $request->attributes->get('auth_user_id'),
            'user_email' => $userEmail,
            'api_client_id' => $request->attributes->get('api_client_id'),
            'submitted_at' => now()->toIso8601String(),
        ]);

        return $data;
    }

    private function download(int $id, string $type, string $ext, string $contentType, string $prefix = ''): StreamedResponse
    {
        $documento = $this->getDocumentoUseCase->execute($id);

        $ruc = (string) data_get($documento, 'payload.empresa.ruc', '00000000000');
        $tipoDocumento = (string) data_get($documento, 'tipo_documento', '01');
        $serie = (string) data_get($documento, 'serie', 'F001');
        $correlativo = (string) data_get($documento, 'correlativo', '1');

        $nameWithType = sprintf('%s%s-%s-%s-%s%s', $prefix, $ruc, $tipoDocumento, $serie, $correlativo, $ext);
        $legacyName = sprintf('%s%s-%s-%s%s', $prefix, $ruc, $serie, $correlativo, $ext);

        $primaryPath = match ($type) {
            'pdf' => $this->storagePathResolver->pdfPath($nameWithType),
            'xml' => $this->storagePathResolver->xmlPath($nameWithType),
            default => $this->storagePathResolver->cdrPath($nameWithType),
        };
        $legacyPath = match ($type) {
            'pdf' => $this->storagePathResolver->pdfPath($legacyName),
            'xml' => $this->storagePathResolver->xmlPath($legacyName),
            default => $this->storagePathResolver->cdrPath($legacyName),
        };

        if ($type === 'pdf') {
            $this->generatePdfOnDemand($documento, $primaryPath);
        }

        $path = Storage::disk('tenants')->exists($primaryPath)
            ? $primaryPath
            : $legacyPath;

        abort_unless(Storage::disk('tenants')->exists($path), 404, strtoupper($type).' not generated yet.');

        return Storage::disk('tenants')->download($path, basename($path), [
            'Content-Type' => $contentType,
        ]);
    }

    private function generatePdfOnDemand(array $documento, string $targetPath): void
    {
        try {
            $payload = (array) data_get($documento, 'payload', []);
            $documentoPayload = (array) data_get($payload, 'documento', []);
            $documentoPayload['tipo'] = (string) data_get($documento, 'tipo_documento', $documentoPayload['tipo'] ?? '01');
            $documentoPayload['serie'] = (string) data_get($documento, 'serie', $documentoPayload['serie'] ?? '');
            $documentoPayload['correlativo'] = (string) data_get($documento, 'correlativo', $documentoPayload['correlativo'] ?? '');

            $pdfContext = [
                'estado' => (string) data_get($documento, 'estado', 'REGISTRADO'),
                'hash' => data_get($documento, 'hash'),
                'mensaje' => $this->buildPdfMessage($documento),
                'empresa' => $this->withTenantBillingLogo((array) data_get($payload, 'empresa', [])),
                'cliente' => (array) data_get($payload, 'cliente', []),
                'documento' => $documentoPayload,
                'detalles' => (array) data_get($payload, 'detalles', []),
            ];

            $pdfBinary = $this->documentPdfGenerator->generate($pdfContext);
            Storage::disk('tenants')->put($targetPath, $pdfBinary);
        } catch (\Throwable $exception) {
            Log::warning('No se pudo generar PDF on-demand.', [
                'documento_id' => $documento['id'] ?? null,
                'target_path' => $targetPath,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function buildPdfMessage(array $documento): string
    {
        $estado = strtoupper((string) data_get($documento, 'estado', 'REGISTRADO'));

        return match ($estado) {
            'ACEPTADO' => 'Comprobante aceptado por SUNAT.',
            'RECHAZADO' => 'Comprobante rechazado por SUNAT.',
            'ERROR' => 'Comprobante con error de procesamiento.',
            'EN_PROCESO', 'PROCESANDO' => 'Comprobante en procesamiento SUNAT.',
            'RECIBIDO' => 'Comprobante recibido y en cola para SUNAT.',
            default => 'Comprobante registrado en facturador.',
        };
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
