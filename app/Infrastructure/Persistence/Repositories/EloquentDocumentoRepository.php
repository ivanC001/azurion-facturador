<?php

namespace App\Infrastructure\Persistence\Repositories;

use App\Domain\Documentos\Contracts\DocumentoRepository;
use App\Domain\Documentos\Enums\DocumentStatus;
use App\Infrastructure\Tenant\TenantSchemaManager;
use App\Models\Documento;
use App\Support\Tenants\TenantContext;
use Illuminate\Support\Facades\DB;

final class EloquentDocumentoRepository implements DocumentoRepository
{
    private const MAX_CORRELATIVO_DIGITS = 8;
    private const MAX_CORRELATIVO_VALUE = 99_999_999;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantSchemaManager $tenantSchemaManager,
    ) {
    }

    public function create(array $payload): Documento
    {
        $tenant = $this->tenantContext->required();
        $this->tenantSchemaManager->ensureProvisioned($tenant->schema);

        $tipoDocumento = (string) ($payload['documento']['tipo'] ?? '01');
        $externalId = trim((string) data_get($payload, 'documento.external_id', ''));

        return DB::transaction(function () use ($payload, $tipoDocumento, $externalId, $tenant): Documento {
            if ($externalId !== '') {
                if (str_contains((string) DB::connection()->getDriverName(), 'pgsql')) {
                    DB::statement(
                        'SELECT pg_advisory_xact_lock(hashtext(?))',
                        [$tenant->schema.'|'.$externalId],
                    );
                }

                $existing = Documento::query()
                    ->where('external_id', $externalId)
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            $documentNumber = $this->resolveDocumentNumber($payload, $tipoDocumento);
            $payload['documento']['serie'] = $documentNumber['serie'];
            $payload['documento']['correlativo'] = $documentNumber['correlativo'];
            $submittedBy = (array) data_get($payload, 'meta.submitted_by', []);

            return Documento::query()->create([
                'tipo_documento' => $tipoDocumento,
                'external_id' => $externalId !== '' ? $externalId : null,
                'serie' => $documentNumber['serie'],
                'correlativo' => $documentNumber['correlativo'],
                'estado' => $tipoDocumento === 'TK'
                    ? DocumentStatus::REGISTERED->value
                    : DocumentStatus::RECEIVED->value,
                'payload' => $payload,
                'empresa' => $payload['empresa'] ?? [],
                'cliente' => $payload['cliente'] ?? [],
                'sucursal' => $payload['sucursal'] ?? [],
                'submitted_by_user_id' => is_numeric($submittedBy['user_id'] ?? null) ? (int) $submittedBy['user_id'] : null,
                'submitted_by_email' => is_string($submittedBy['user_email'] ?? null) ? (string) $submittedBy['user_email'] : null,
                'submitted_by_api_client_id' => is_numeric($submittedBy['api_client_id'] ?? null) ? (int) $submittedBy['api_client_id'] : null,
                'submitted_by_auth_mode' => is_string($submittedBy['auth_mode'] ?? null) ? (string) $submittedBy['auth_mode'] : null,
            ]);
        });
    }

    /**
     * @return array{serie: string, correlativo: string}
     */
    private function resolveDocumentNumber(array $payload, string $tipoDocumento): array
    {
        $serieFromPayload = trim((string) ($payload['documento']['serie'] ?? ''));

        $sucursalCodigo = trim((string) data_get($payload, 'sucursal.codigo', ''));
        if ($sucursalCodigo !== '') {
            $serieRow = DB::table('series')
                ->where('sucursal_codigo', $sucursalCodigo)
                ->where('tipo_documento', $tipoDocumento)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($serieRow !== null) {
                $serie = (string) $serieRow->serie;
                $lastInSeries = $this->maxNumericCorrelativo($tipoDocumento, $serie);
                $currentSerieValue = $this->normalizeCorrelativoValue((int) $serieRow->correlativo_actual);
                $next = max($currentSerieValue, $lastInSeries) + 1;
                $next = $this->normalizeCorrelativoValue($next);
                DB::table('series')->where('id', $serieRow->id)->update([
                    'correlativo_actual' => $next,
                    'updated_at' => now(),
                ]);

                return [
                    'serie' => $serie,
                    'correlativo' => (string) $next,
                ];
            }

            $sucursal = DB::table('sucursales')
                ->where('codigo', $sucursalCodigo)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($sucursal !== null) {
                $serie = $serieFromPayload !== ''
                    ? $serieFromPayload
                    : $this->defaultSerieForSucursal($tipoDocumento, (int) $sucursal->numero);
                $next = $this->maxNumericCorrelativo($tipoDocumento, $serie) + 1;
                $next = $this->normalizeCorrelativoValue($next);

                DB::table('series')->updateOrInsert(
                    [
                        'sucursal_codigo' => $sucursalCodigo,
                        'tipo_documento' => $tipoDocumento,
                    ],
                    [
                        'sucursal_id' => $sucursal->id,
                        'serie' => $serie,
                        'correlativo_actual' => $next,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );

                return [
                    'serie' => $serie,
                    'correlativo' => (string) $next,
                ];
            }
        }

        $serie = $serieFromPayload !== '' ? $serieFromPayload : $this->defaultSerie($tipoDocumento);
        $next = $this->nextCorrelativoWithoutSucursal($tipoDocumento, $serie);

        return [
            'serie' => $serie,
            'correlativo' => (string) $next,
        ];
    }

    private function nextCorrelativoWithoutSucursal(string $tipoDocumento, string $serie): int
    {
        return $this->normalizeCorrelativoValue($this->maxNumericCorrelativo($tipoDocumento, $serie) + 1);
    }

    private function maxNumericCorrelativo(string $tipoDocumento, string $serie): int
    {
        $driver = (string) DB::connection()->getDriverName();

        if (str_contains($driver, 'pgsql')) {
            $row = DB::table('documentos')
                ->where('tipo_documento', $tipoDocumento)
                ->where('serie', $serie)
                ->whereRaw("correlativo ~ '^[0-9]+$'")
                ->whereRaw('LENGTH(correlativo) <= ?', [self::MAX_CORRELATIVO_DIGITS])
                ->orderByRaw('CAST(correlativo AS BIGINT) DESC')
                ->lockForUpdate()
                ->value('correlativo');

            if ($row === null || ! is_numeric($row)) {
                return 0;
            }

            return $this->normalizeCorrelativoValue((int) $row);
        }

        $rows = DB::table('documentos')
            ->where('tipo_documento', $tipoDocumento)
            ->where('serie', $serie)
            ->lockForUpdate()
            ->pluck('correlativo');

        $max = 0;
        foreach ($rows as $value) {
            if (is_numeric($value) && strlen((string) $value) <= self::MAX_CORRELATIVO_DIGITS) {
                $max = max($max, $this->normalizeCorrelativoValue((int) $value));
            }
        }

        return $max;
    }

    private function normalizeCorrelativoValue(int $value): int
    {
        if ($value < 1 || $value > self::MAX_CORRELATIVO_VALUE) {
            return 1;
        }

        return $value;
    }

    private function defaultSerie(string $tipoDocumento): string
    {
        return match ($tipoDocumento) {
            '03' => 'B001',
            '07' => 'FC01',
            '08' => 'FD01',
            '09' => 'T001',
            'TK' => 'TK01',
            default => 'F001',
        };
    }

    private function defaultSerieForSucursal(string $tipoDocumento, int $sucursalNumber): string
    {
        $three = str_pad((string) $sucursalNumber, 3, '0', STR_PAD_LEFT);
        $two = str_pad((string) $sucursalNumber, 2, '0', STR_PAD_LEFT);

        return match ($tipoDocumento) {
            '03' => 'B'.$three,
            '07' => 'FC'.$two,
            '08' => 'FD'.$two,
            '09' => 'T'.$three,
            'TK' => 'TK'.$two,
            default => 'F'.$three,
        };
    }

    public function findOrFail(int $id): Documento
    {
        return Documento::query()->findOrFail($id);
    }

    public function markProcessing(Documento $documento): void
    {
        $documento->forceFill(['estado' => DocumentStatus::IN_PROCESS->value])->save();
    }

    public function markResult(Documento $documento, string $estado, ?string $ticket = null, ?string $hash = null): void
    {
        $documento->forceFill([
            'estado' => $estado,
            'ticket' => $ticket,
            'hash' => $hash,
        ])->save();
    }
}
