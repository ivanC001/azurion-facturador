<?php

namespace App\Infrastructure\Persistence\Repositories;

use App\Domain\Documentos\Contracts\DocumentoRepository;
use App\Domain\Documentos\Enums\DocumentStatus;
use App\Domain\Documentos\Exceptions\CorrelativoAgotadoException;
use App\Infrastructure\Tenant\TenantSchemaManager;
use App\Models\Documento;
use App\Support\Tenants\TenantContext;
use Illuminate\Support\Facades\DB;
use stdClass;

final class EloquentDocumentoRepository implements DocumentoRepository
{
    private const MAX_CORRELATIVO_DIGITS = 8;

    private const MAX_CORRELATIVO_VALUE = 99_999_999;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantSchemaManager $tenantSchemaManager,
    ) {}

    public function create(array $payload): Documento
    {
        $tenant = $this->tenantContext->required();
        $this->tenantSchemaManager->ensureProvisioned($tenant->schema);

        $tipoDocumento = (string) ($payload['documento']['tipo'] ?? '01');
        $externalId = trim((string) data_get($payload, 'documento.external_id', ''));

        return DB::transaction(function () use ($payload, $tipoDocumento, $externalId, $tenant): Documento {
            if ($externalId !== '') {
                $this->advisoryLock($tenant->schema, 'external_id', $externalId);

                $existing = Documento::query()
                    ->where('external_id', $externalId)
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            $documentNumber = $this->resolveDocumentNumber($tenant->schema, $payload, $tipoDocumento);
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
     * Resuelve serie y correlativo del comprobante.
     *
     * El orden importa: primero se decide la serie sin efectos secundarios,
     * despues se serializa a todos los emisores de esa serie y solo entonces
     * se calcula el correlativo. Bloquear una vez conocida la serie es lo que
     * evita que dos peticiones concurrentes emitan el mismo numero.
     *
     * @param  array<string, mixed>  $payload
     * @return array{serie: string, correlativo: string}
     */
    private function resolveDocumentNumber(string $schema, array $payload, string $tipoDocumento): array
    {
        $serieFromPayload = trim((string) ($payload['documento']['serie'] ?? ''));
        $sucursalCodigo = trim((string) data_get($payload, 'sucursal.codigo', ''));

        $serie = $this->resolveSerie($tipoDocumento, $sucursalCodigo, $serieFromPayload);

        $this->advisoryLock($schema, 'serie', $tipoDocumento.'|'.$serie);

        $serieRow = $sucursalCodigo !== ''
            ? $this->findSerieRow($sucursalCodigo, $tipoDocumento)
            : null;

        $next = $this->assertCorrelativoInRange(
            max($this->maxNumericCorrelativo($tipoDocumento, $serie), $this->storedCorrelativo($serieRow)) + 1,
            $tipoDocumento,
            $serie,
        );

        $this->persistSerieCounter($sucursalCodigo, $tipoDocumento, $serie, $serieRow, $next);

        return [
            'serie' => $serie,
            'correlativo' => (string) $next,
        ];
    }

    /**
     * Decide que serie corresponde al comprobante. Solo lee: no incrementa
     * contadores ni crea filas, para poder llamarse antes de tomar el lock.
     */
    private function resolveSerie(string $tipoDocumento, string $sucursalCodigo, string $serieFromPayload): string
    {
        if ($sucursalCodigo !== '') {
            $serieRow = $this->findSerieRow($sucursalCodigo, $tipoDocumento);
            if ($serieRow !== null) {
                // La serie configurada para la sucursal manda sobre el payload.
                return (string) $serieRow->serie;
            }

            $sucursal = $this->findSucursal($sucursalCodigo);
            if ($sucursal !== null) {
                return $serieFromPayload !== ''
                    ? $serieFromPayload
                    : $this->defaultSerieForSucursal($tipoDocumento, (int) $sucursal->numero);
            }
        }

        return $serieFromPayload !== '' ? $serieFromPayload : $this->defaultSerie($tipoDocumento);
    }

    /**
     * Deja el contador de la serie alineado con el correlativo recien emitido.
     */
    private function persistSerieCounter(
        string $sucursalCodigo,
        string $tipoDocumento,
        string $serie,
        ?stdClass $serieRow,
        int $next,
    ): void {
        if ($serieRow !== null) {
            DB::table('series')->where('id', $serieRow->id)->update([
                'correlativo_actual' => $next,
                'updated_at' => now(),
            ]);

            return;
        }

        if ($sucursalCodigo === '') {
            return;
        }

        $sucursal = $this->findSucursal($sucursalCodigo);
        if ($sucursal === null) {
            return;
        }

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
    }

    private function findSerieRow(string $sucursalCodigo, string $tipoDocumento): ?stdClass
    {
        return DB::table('series')
            ->where('sucursal_codigo', $sucursalCodigo)
            ->where('tipo_documento', $tipoDocumento)
            ->where('is_active', true)
            ->first();
    }

    private function findSucursal(string $sucursalCodigo): ?stdClass
    {
        return DB::table('sucursales')
            ->where('codigo', $sucursalCodigo)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Ultimo correlativo realmente emitido en la serie, o 0 si esta vacia.
     *
     * No usa lockForUpdate: en una serie sin documentos no habria ninguna fila
     * que bloquear. La exclusion mutua la aporta el advisory lock de la serie.
     */
    private function maxNumericCorrelativo(string $tipoDocumento, string $serie): int
    {
        $query = DB::table('documentos')
            ->where('tipo_documento', $tipoDocumento)
            ->where('serie', $serie)
            ->whereRaw('LENGTH(correlativo) <= ?', [self::MAX_CORRELATIVO_DIGITS]);

        if ($this->usesPostgres()) {
            // Postgres aborta el CAST si el texto no es numerico, asi que las
            // filas heredadas no numericas se descartan antes de ordenar.
            $query->whereRaw("correlativo ~ '^[0-9]+$'")
                ->orderByRaw('CAST(correlativo AS BIGINT) DESC');
        } else {
            $query->orderByRaw('CAST(correlativo AS INTEGER) DESC');
        }

        $value = $query->value('correlativo');

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /**
     * Contador persistido de la serie. Se respeta tal cual: un 0 significa
     * "aun no se emitio nada", de modo que el primer comprobante sea el 1.
     */
    private function storedCorrelativo(?stdClass $serieRow): int
    {
        return max(0, (int) ($serieRow->correlativo_actual ?? 0));
    }

    private function assertCorrelativoInRange(int $value, string $tipoDocumento, string $serie): int
    {
        if ($value < 1 || $value > self::MAX_CORRELATIVO_VALUE) {
            throw new CorrelativoAgotadoException(
                $tipoDocumento,
                $serie,
                $value,
                self::MAX_CORRELATIVO_VALUE,
            );
        }

        return $value;
    }

    /**
     * Serializa a los emisores que compiten por el mismo recurso del tenant.
     * El lock se libera solo al cerrar la transaccion.
     */
    private function advisoryLock(string $schema, string $namespace, string $key): void
    {
        if (! $this->usesPostgres()) {
            return;
        }

        DB::statement(
            'SELECT pg_advisory_xact_lock(hashtext(?))',
            [$schema.'|'.$namespace.'|'.$key],
        );
    }

    private function usesPostgres(): bool
    {
        return str_contains((string) DB::connection()->getDriverName(), 'pgsql');
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
