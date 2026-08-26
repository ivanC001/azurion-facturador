<?php

namespace App\Http\Controllers\Api;

use App\Application\Sunat\UseCases\DispatchSunatEnvioUseCase;
use App\Application\Sunat\UseCases\GetSunatStatusUseCase;
use App\Infrastructure\Tenant\TenantArtifactStorage;
use App\Infrastructure\Tenant\TenantStoragePathResolver;
use App\Models\Documento;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SunatController
{
    public function __construct(
        private readonly DispatchSunatEnvioUseCase $dispatchSunatEnvioUseCase,
        private readonly GetSunatStatusUseCase $getSunatStatusUseCase,
        private readonly TenantStoragePathResolver $storagePathResolver,
        private readonly TenantArtifactStorage $artifactStorage,
    ) {}

    public function enviar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'documento_id' => ['required', 'integer'],
        ]);

        $documento = Documento::query()->findOrFail((int) $data['documento_id']);
        abort_if($documento->tipo_documento === 'TK', 422, 'Ticket de venta solo se registra en base de datos; no se envia a SUNAT.');

        $this->dispatchSunatEnvioUseCase->execute((int) $data['documento_id']);

        return ApiResponse::accepted([
            'estado' => 'EN_COLA',
            'documento_id' => (int) $data['documento_id'],
        ]);
    }

    public function estado(Request $request): JsonResponse
    {
        $documentoId = $request->integer('documento_id');
        $status = $this->getSunatStatusUseCase->execute($documentoId);
        $baseName = sprintf(
            '%s-%s-%s-%s',
            $status['empresa_ruc'] ?? '00000000000',
            $status['tipo_documento'] ?? '01',
            $status['serie'] ?? 'F001',
            $status['correlativo'] ?? '1',
        );

        $xmlPath = $this->storagePathResolver->xmlPath($baseName.'.xml');
        $pdfPath = $this->storagePathResolver->pdfPath($baseName.'.pdf');
        $cdrPath = $this->storagePathResolver->cdrPath('R-'.$baseName.'.zip');

        $xmlExists = $this->artifactStorage->exists($xmlPath);
        $pdfExists = $this->artifactStorage->exists($pdfPath);
        $cdrExists = $this->artifactStorage->exists($cdrPath);

        $baseUrl = rtrim(config('app.url'), '/');
        $tenantRuc = (string) ($status['empresa_ruc'] ?? '00000000000');
        $query = '?tenant_ruc='.urlencode($tenantRuc);

        $status['artifacts'] = [
            'xml' => [
                'generated' => $xmlExists,
                'url' => $xmlExists ? $baseUrl.'/api/documentos/'.$status['documento_id'].'/xml'.$query : null,
            ],
            'pdf' => [
                'generated' => $pdfExists,
                'url' => $pdfExists ? $baseUrl.'/api/documentos/'.$status['documento_id'].'/pdf'.$query : null,
            ],
            'cdr' => [
                'generated' => $cdrExists,
                'url' => $cdrExists ? $baseUrl.'/api/documentos/'.$status['documento_id'].'/cdr'.$query : null,
            ],
        ];

        return ApiResponse::success($status);
    }
}
