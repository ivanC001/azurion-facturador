<?php

namespace App\Jobs;

use App\Application\Integrations\Azurion\AzurionVentaStatusNotifier;
use App\Domain\Documentos\Contracts\DocumentoRepository;
use App\Domain\Documentos\Enums\DocumentStatus;
use App\Domain\Documentos\Events\DocumentoProcesado;
use App\Domain\Sunat\Contracts\SunatSender;
use App\Infrastructure\Tenant\TenantArtifactStorage;
use App\Infrastructure\Tenant\TenantStoragePathResolver;
use App\Models\Documento;
use App\Models\DocumentoSunat;
use App\Models\Tenant;
use App\Support\Tenants\TenantContext;
use App\Support\Tenants\TenantIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

abstract class AbstractProcessSunatDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /**
     * Must remain lower than Redis retry_after so an in-flight job is never
     * delivered to a second worker before the first worker times out.
     */
    public int $timeout = 120;

    /**
     * Avoids an immediate retry storm when SUNAT or the ERP callback is down.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    /**
     * Prevents two deliveries of the same Redis job from sending one fiscal
     * document concurrently. A later duplicate is harmless because handle()
     * also checks terminal states before contacting SUNAT.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(sprintf('sunat:%d:%d', $this->tenantId, $this->documentoId)))
                ->releaseAfter(15)
                ->expireAfter(180),
        ];
    }

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
        TenantArtifactStorage $artifactStorage,
    ): void {
        try {
            $tenant = $this->resolveCurrentTenant();

            if (Str::contains((string) DB::connection()->getDriverName(), 'pgsql')) {
                DB::statement(sprintf('SET search_path TO "%s", public', $tenant->schema_name));
            }

            $tenantContext->set(new TenantIdentity(
                tenantId: (int) $tenant->id,
                ruc: (string) $tenant->ruc,
                schema: (string) $tenant->schema_name,
                sunatMode: (string) $tenant->sunat_mode,
                countryCode: (string) $tenant->country_code,
                documentMode: (string) $tenant->document_mode,
                fiscalStatus: (string) $tenant->fiscal_status,
            ));

            Log::channel('sunat')->info('Inicio de procesamiento SUNAT.', [
                'documento_id' => $this->documentoId,
                'tenant_id' => $this->tenantId,
                'entorno' => $this->sunatMode,
                'cola' => $this->queue,
            ]);

            $documento = $documentoRepository->findOrFail($this->documentoId);
            if (in_array($documento->estado, [
                DocumentStatus::ACCEPTED->value,
                DocumentStatus::REJECTED->value,
            ], true)) {
                $documento->load('sunat');
                if ($azurionVentaStatusNotifier->isEnabled()
                    && ! $azurionVentaStatusNotifier->notify($documento)) {
                    throw new RuntimeException('El callback de estado hacia Azurion no pudo confirmarse.');
                }
                Log::channel('sunat')->info('Documento SUNAT ya finalizado; se omite entrega duplicada.', [
                    'documento_id' => $this->documentoId,
                    'tenant_id' => $this->tenantId,
                    'estado' => $documento->estado,
                ]);

                return;
            }
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
                $artifactStorage->put($storagePathResolver->xmlPath($baseName.'.xml'), $result['xml']);
            }

            if (isset($result['pdf'])) {
                $artifactStorage->put($storagePathResolver->pdfPath($baseName.'.pdf'), $result['pdf']);
            }

            if (isset($result['cdr'])) {
                $artifactStorage->put($storagePathResolver->cdrPath('R-'.$baseName.'.zip'), $result['cdr']);
            }

            $documento->refresh();
            $documento->load('sunat');
            $callbackDelivered = $azurionVentaStatusNotifier->notify($documento);
            if ($azurionVentaStatusNotifier->isEnabled()
                && in_array($result['estado'], [
                    DocumentStatus::ACCEPTED->value,
                    DocumentStatus::REJECTED->value,
                ], true)
                && ! $callbackDelivered) {
                // The retry starts in the terminal-state branch above and only
                // repeats the callback; it never sends the fiscal document twice.
                throw new RuntimeException('El callback de estado hacia Azurion no pudo confirmarse.');
            }

            if ($result['estado'] === DocumentStatus::ACCEPTED->value) {
                DocumentoProcesado::dispatch($documento->id, $result['estado']);
            }
        } catch (Throwable $exception) {
            Log::channel('sunat')->error('Fallo del job de procesamiento SUNAT.', [
                'documento_id' => $this->documentoId,
                'tenant_id' => $this->tenantId,
                'entorno' => $this->sunatMode,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            $tenantContext->clear();

            if (Str::contains((string) DB::connection()->getDriverName(), 'pgsql')) {
                DB::statement('SET search_path TO public');
            }
        }
    }

    private function resolveCurrentTenant(): Tenant
    {
        $tenant = Tenant::query()
            ->whereKey($this->tenantId)
            ->where('is_active', true)
            ->first();

        if ($tenant === null) {
            throw new RuntimeException('El tenant del documento no existe o esta inactivo.');
        }

        if ((string) $tenant->ruc !== $this->tenantRuc
            || (string) $tenant->schema_name !== $this->tenantSchema) {
            throw new RuntimeException('La identidad del tenant encolado no coincide con el tenant actual.');
        }

        if ((string) $tenant->sunat_mode !== $this->sunatMode) {
            throw new RuntimeException(sprintf(
                'El modo SUNAT cambio de %s a %s despues de encolar el documento; vuelve a solicitar el envio.',
                $this->sunatMode,
                (string) $tenant->sunat_mode,
            ));
        }

        if (! $tenant->allowsElectronicDocuments()) {
            throw new RuntimeException('El tenant ya no tiene habilitada la facturacion electronica SUNAT.');
        }

        return $tenant;
    }

    private function buildBaseName(Documento $documento): string
    {
        $ruc = (string) data_get($documento->empresa, 'ruc', '00000000000');

        return sprintf('%s-%s-%s-%s', $ruc, $documento->tipo_documento, $documento->serie, $documento->correlativo);
    }
}
