<?php

namespace App\Jobs;

use App\Application\Integrations\Azurion\AzurionVentaStatusNotifier;
use App\Domain\Documentos\Contracts\DocumentoRepository;
use App\Domain\Documentos\Enums\DocumentStatus;
use App\Domain\Documentos\Events\DocumentoProcesado;
use App\Domain\Sunat\Contracts\SunatSender;
use App\Infrastructure\Tenant\TenantStoragePathResolver;
use App\Models\Documento;
use App\Models\DocumentoSunat;
use App\Support\Tenants\TenantContext;
use App\Support\Tenants\TenantIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

abstract class AbstractProcessSunatDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $documentoId;

    public int $tenantId;

    public string $tenantRuc;

    public string $tenantSchema;

    public string $sunatMode;

    public function __construct(
        int $documentoId,
        int $tenantId,
        string $tenantRuc,
        string $tenantSchema,
        string $sunatMode,
        string $queueName,
    ) {
        $this->documentoId = $documentoId;
        $this->tenantId = $tenantId;
        $this->tenantRuc = $tenantRuc;
        $this->tenantSchema = $tenantSchema;
        $this->sunatMode = $sunatMode;
        $this->onQueue($queueName);
    }

    public function handle(
        DocumentoRepository $documentoRepository,
        SunatSender $sunatSender,
        TenantStoragePathResolver $storagePathResolver,
        TenantContext $tenantContext,
        AzurionVentaStatusNotifier $azurionVentaStatusNotifier,
    ): void {
        if (Str::contains((string) DB::connection()->getDriverName(), 'pgsql')) {
            DB::statement(sprintf('SET search_path TO "%s", public', $this->tenantSchema));
        }

        $tenantContext->set(new TenantIdentity(
            tenantId: $this->tenantId,
            ruc: $this->tenantRuc,
            schema: $this->tenantSchema,
            sunatMode: $this->sunatMode,
        ));

        try {
            $documento = $documentoRepository->findOrFail($this->documentoId);
            $documentoRepository->markProcessing($documento);

            $result = $sunatSender->send($documento);

            $documentoRepository->markResult(
                documento: $documento,
                estado: $result['estado'],
                ticket: $result['ticket'] ?? null,
                hash: $result['hash'] ?? null,
            );

            DocumentoSunat::query()->updateOrCreate([
                'documento_id' => $documento->id,
            ], [
                'estado' => $result['estado'],
                'codigo_error' => $result['codigo_error'] ?? null,
                'mensaje' => $result['mensaje'] ?? null,
            ]);

            $baseName = $this->buildBaseName($documento);

            if (isset($result['xml'])) {
                Storage::disk('tenants')->put($storagePathResolver->xmlPath($baseName.'.xml'), $result['xml']);
            }

            if (isset($result['pdf'])) {
                Storage::disk('tenants')->put($storagePathResolver->pdfPath($baseName.'.pdf'), $result['pdf']);
            }

            if (isset($result['cdr'])) {
                Storage::disk('tenants')->put($storagePathResolver->cdrPath('R-'.$baseName.'.zip'), $result['cdr']);
            }

            $documento->refresh();
            $documento->load('sunat');
            $azurionVentaStatusNotifier->notify($documento);

            if ($result['estado'] === DocumentStatus::ACCEPTED->value) {
                DocumentoProcesado::dispatch($documento->id, $result['estado']);
            }
        } finally {
            $tenantContext->clear();
        }
    }

    private function buildBaseName(Documento $documento): string
    {
        $ruc = (string) data_get($documento->empresa, 'ruc', '00000000000');

        return sprintf('%s-%s-%s-%s', $ruc, $documento->tipo_documento, $documento->serie, $documento->correlativo);
    }
}
