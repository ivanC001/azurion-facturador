<?php

namespace App\Infrastructure\Tenant;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class TenantSchemaManager
{
    private const PROVISIONING_CACHE_VERSION = 'v4';

    public function ensureProvisioned(string $schema): void
    {
        // Provisioning performs many DDL statements. It belongs to tenant
        // onboarding and must not periodically slow down document emission.
        // Bump the version whenever provision() adds a schema change.
        Cache::rememberForever(
            'facturador:schema:provisioned:'.self::PROVISIONING_CACHE_VERSION.':'.$schema,
            function () use ($schema): bool {
                $this->provision($schema);

                return true;
            },
        );
    }

    public function provision(string $schema): void
    {
        $driver = (string) DB::connection()->getDriverName();

        if (! Str::contains($driver, 'pgsql')) {
            if ($driver === 'sqlite') {
                $this->provisionSqliteAttachedSchema($schema);
            }
            $this->markProvisioned($schema);

            return;
        }

        if (! preg_match('/^[a-zA-Z0-9_]+$/', $schema)) {
            throw new \InvalidArgumentException('Invalid schema name.');
        }

        DB::statement(sprintf('CREATE SCHEMA IF NOT EXISTS "%s"', $schema));

        $statements = [
            'configuracion_facturacion',
            'documentos',
            'documento_detalles',
            'documento_tributos',
            'documento_totales',
            'documento_sunat',
            'documento_referencias',
            'documento_cuotas',
            'xml_storage',
            'pdf_storage',
            'cdr_storage',
            'sucursales',
            'series',
            'correlativos',
            'certificados_digitales',
            'guias_remision',
            'guias_detalles',
            'resumenes_diarios',
            'bajas_documentos',
            'logs_sunat',
            'errores_envio',
            'auditoria',
        ];

        foreach ($statements as $table) {
            DB::statement(sprintf('CREATE TABLE IF NOT EXISTS "%s"."%s" (id BIGSERIAL PRIMARY KEY, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL)', $schema, $table));
        }

        DB::statement(sprintf('ALTER TABLE "%s"."documentos" ADD COLUMN IF NOT EXISTS tipo_documento VARCHAR(3)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."documentos" ADD COLUMN IF NOT EXISTS external_id VARCHAR(120)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."documentos" ADD COLUMN IF NOT EXISTS serie VARCHAR(10)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."documentos" ADD COLUMN IF NOT EXISTS correlativo VARCHAR(20)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."documentos" ADD COLUMN IF NOT EXISTS estado VARCHAR(25)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."documentos" ADD COLUMN IF NOT EXISTS payload JSONB', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."documentos" ADD COLUMN IF NOT EXISTS hash VARCHAR(128)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."documentos" ADD COLUMN IF NOT EXISTS ticket VARCHAR(80)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."documentos" ADD COLUMN IF NOT EXISTS cliente JSONB', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."documentos" ADD COLUMN IF NOT EXISTS empresa JSONB', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."documentos" ADD COLUMN IF NOT EXISTS sucursal JSONB', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."documentos" ADD COLUMN IF NOT EXISTS submitted_by_user_id BIGINT', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."documentos" ADD COLUMN IF NOT EXISTS submitted_by_email VARCHAR(255)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."documentos" ADD COLUMN IF NOT EXISTS submitted_by_api_client_id BIGINT', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."documentos" ADD COLUMN IF NOT EXISTS submitted_by_auth_mode VARCHAR(20)', $schema));

        DB::statement(sprintf('ALTER TABLE "%s"."documento_sunat" ADD COLUMN IF NOT EXISTS documento_id BIGINT', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."documento_sunat" ADD COLUMN IF NOT EXISTS estado VARCHAR(25)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."documento_sunat" ADD COLUMN IF NOT EXISTS codigo_error VARCHAR(12)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."documento_sunat" ADD COLUMN IF NOT EXISTS mensaje TEXT', $schema));

        DB::statement(sprintf('ALTER TABLE "%s"."xml_storage" ADD COLUMN IF NOT EXISTS documento_id BIGINT', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."xml_storage" ADD COLUMN IF NOT EXISTS file_path VARCHAR(500)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."pdf_storage" ADD COLUMN IF NOT EXISTS documento_id BIGINT', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."pdf_storage" ADD COLUMN IF NOT EXISTS file_path VARCHAR(500)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."cdr_storage" ADD COLUMN IF NOT EXISTS documento_id BIGINT', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."cdr_storage" ADD COLUMN IF NOT EXISTS file_path VARCHAR(500)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."auditoria" ADD COLUMN IF NOT EXISTS action VARCHAR(120)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."auditoria" ADD COLUMN IF NOT EXISTS documento_id BIGINT', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."auditoria" ADD COLUMN IF NOT EXISTS payload JSONB', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."auditoria" ADD COLUMN IF NOT EXISTS performed_at TIMESTAMP', $schema));

        DB::statement(sprintf('ALTER TABLE "%s"."configuracion_facturacion" ADD COLUMN IF NOT EXISTS usuario_sol VARCHAR(120)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."configuracion_facturacion" ADD COLUMN IF NOT EXISTS ruc_sol VARCHAR(11)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."configuracion_facturacion" ADD COLUMN IF NOT EXISTS clave_sol_encrypted TEXT', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."configuracion_facturacion" ADD COLUMN IF NOT EXISTS certificado_url VARCHAR(500)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."configuracion_facturacion" ADD COLUMN IF NOT EXISTS certificado_password TEXT', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."configuracion_facturacion" ADD COLUMN IF NOT EXISTS modo_sunat VARCHAR(20)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."configuracion_facturacion" ADD COLUMN IF NOT EXISTS logo_pdf_url VARCHAR(500)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."configuracion_facturacion" ADD COLUMN IF NOT EXISTS serie_factura VARCHAR(10)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."configuracion_facturacion" ADD COLUMN IF NOT EXISTS serie_boleta VARCHAR(10)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."configuracion_facturacion" ADD COLUMN IF NOT EXISTS serie_nc VARCHAR(10)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."configuracion_facturacion" ADD COLUMN IF NOT EXISTS serie_nd VARCHAR(10)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."configuracion_facturacion" ADD COLUMN IF NOT EXISTS serie_guia VARCHAR(10)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."configuracion_facturacion" ADD COLUMN IF NOT EXISTS igv NUMERIC(8,2)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."configuracion_facturacion" ADD COLUMN IF NOT EXISTS moneda VARCHAR(3)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."configuracion_facturacion" ADD COLUMN IF NOT EXISTS token_api VARCHAR(255)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."configuracion_facturacion" ADD COLUMN IF NOT EXISTS cuentas_bancarias JSONB', $schema));

        DB::statement(sprintf('ALTER TABLE "%s"."sucursales" ADD COLUMN IF NOT EXISTS codigo VARCHAR(30)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."sucursales" ADD COLUMN IF NOT EXISTS numero INTEGER', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."sucursales" ADD COLUMN IF NOT EXISTS nombre VARCHAR(180)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."sucursales" ADD COLUMN IF NOT EXISTS direccion VARCHAR(500)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."sucursales" ADD COLUMN IF NOT EXISTS ubigeo VARCHAR(6)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."sucursales" ADD COLUMN IF NOT EXISTS departamento VARCHAR(80)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."sucursales" ADD COLUMN IF NOT EXISTS provincia VARCHAR(80)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."sucursales" ADD COLUMN IF NOT EXISTS distrito VARCHAR(80)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."sucursales" ADD COLUMN IF NOT EXISTS cod_local VARCHAR(10)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."sucursales" ADD COLUMN IF NOT EXISTS is_active BOOLEAN DEFAULT TRUE', $schema));

        DB::statement(sprintf('ALTER TABLE "%s"."series" ADD COLUMN IF NOT EXISTS sucursal_id BIGINT', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."series" ADD COLUMN IF NOT EXISTS sucursal_codigo VARCHAR(30)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."series" ADD COLUMN IF NOT EXISTS tipo_documento VARCHAR(3)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."series" ADD COLUMN IF NOT EXISTS serie VARCHAR(10)', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."series" ADD COLUMN IF NOT EXISTS correlativo_actual BIGINT DEFAULT 0', $schema));
        DB::statement(sprintf('ALTER TABLE "%s"."series" ADD COLUMN IF NOT EXISTS is_active BOOLEAN DEFAULT TRUE', $schema));

        DB::statement(sprintf(
            'WITH current_max AS (
                SELECT COALESCE(MAX(numero), 0)::INTEGER AS value
                FROM "%s"."sucursales"
            ),
            numbered AS (
                SELECT id, (current_max.value + ROW_NUMBER() OVER (ORDER BY id))::INTEGER AS rn
                FROM "%s"."sucursales", current_max
                WHERE numero IS NULL
            )
            UPDATE "%s"."sucursales" s
            SET numero = numbered.rn
            FROM numbered
            WHERE s.id = numbered.id',
            $schema,
            $schema,
            $schema,
        ));

        DB::statement(sprintf('CREATE UNIQUE INDEX IF NOT EXISTS "%s" ON "%s"."sucursales" (codigo)', $this->indexName($schema, 'sucursales_codigo_unique'), $schema));
        DB::statement(sprintf('CREATE UNIQUE INDEX IF NOT EXISTS "%s" ON "%s"."sucursales" (numero)', $this->indexName($schema, 'sucursales_numero_unique'), $schema));
        DB::statement(sprintf('CREATE UNIQUE INDEX IF NOT EXISTS "%s" ON "%s"."series" (sucursal_codigo, tipo_documento)', $this->indexName($schema, 'series_sucursal_tipo_unique'), $schema));
        DB::statement(sprintf('CREATE INDEX IF NOT EXISTS "%s" ON "%s"."documentos" (id DESC)', $this->indexName($schema, 'documentos_id_desc_idx'), $schema));
        DB::statement(sprintf('CREATE INDEX IF NOT EXISTS "%s" ON "%s"."documentos" (estado, id DESC)', $this->indexName($schema, 'documentos_estado_id_idx'), $schema));
        DB::statement(sprintf('CREATE INDEX IF NOT EXISTS "%s" ON "%s"."documentos" (tipo_documento, id DESC)', $this->indexName($schema, 'documentos_tipo_id_idx'), $schema));
        $this->ensureDocumentNumberUniqueness($schema);
        DB::statement(sprintf('CREATE INDEX IF NOT EXISTS "%s" ON "%s"."documentos" ((payload->\'documento\'->>\'external_id\'))', $this->indexName($schema, 'documentos_external_id_idx'), $schema));
        DB::statement(sprintf('CREATE UNIQUE INDEX IF NOT EXISTS "%s" ON "%s"."documentos" (external_id) WHERE external_id IS NOT NULL', $this->indexName($schema, 'documentos_external_id_unique'), $schema));
        DB::statement(sprintf('CREATE UNIQUE INDEX IF NOT EXISTS "%s" ON "%s"."documento_sunat" (documento_id)', $this->indexName($schema, 'documento_sunat_documento_unique'), $schema));
        DB::statement(sprintf('CREATE INDEX IF NOT EXISTS "%s" ON "%s"."auditoria" (documento_id)', $this->indexName($schema, 'auditoria_documento_idx'), $schema));
        DB::statement(sprintf('CREATE INDEX IF NOT EXISTS "%s" ON "%s"."auditoria" (action, performed_at DESC)', $this->indexName($schema, 'auditoria_action_performed_idx'), $schema));
        $this->markProvisioned($schema);
    }

    /**
     * Un comprobante queda identificado por tipo + serie + correlativo, asi que
     * ese trio debe ser unico: sin la restriccion, una carrera entre dos
     * emisiones concurrentes se persiste en silencio y SUNAT rechaza la copia.
     *
     * Si el esquema ya arrastra duplicados, la restriccion no puede crearse.
     * En ese caso se deja el indice no unico, se registra el incidente con el
     * detalle de los numeros afectados y la emision sigue operativa: nunca se
     * corrigen datos fiscales de forma automatica.
     */
    private function ensureDocumentNumberUniqueness(string $schema): void
    {
        $indexName = $this->indexName($schema, 'documentos_tipo_serie_corr_idx');
        $uniqueIndexName = $this->indexName($schema, 'documentos_numero_unique');

        DB::statement(sprintf(
            'CREATE INDEX IF NOT EXISTS "%s" ON "%s"."documentos" (tipo_documento, serie, correlativo)',
            $indexName,
            $schema,
        ));

        $duplicates = DB::select(sprintf(
            'SELECT tipo_documento, serie, correlativo, COUNT(*) AS total
               FROM "%s"."documentos"
              GROUP BY tipo_documento, serie, correlativo
             HAVING COUNT(*) > 1
              LIMIT 20',
            $schema,
        ));

        if ($duplicates !== []) {
            Log::channel('sunat')->error(
                'El esquema del tenant tiene comprobantes con numero duplicado; '
                .'no se pudo activar la restriccion de unicidad. Corrige los duplicados manualmente.',
                [
                    'schema' => $schema,
                    'duplicados' => $duplicates,
                ],
            );

            return;
        }

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX IF NOT EXISTS "%s" ON "%s"."documentos" (tipo_documento, serie, correlativo)',
            $uniqueIndexName,
            $schema,
        ));
    }

    private function markProvisioned(string $schema): void
    {
        Cache::forever(
            'facturador:schema:provisioned:'.self::PROVISIONING_CACHE_VERSION.':'.$schema,
            true,
        );
    }

    private function indexName(string $schema, string $suffix): string
    {
        $name = $schema.'_'.$suffix;
        if (strlen($name) <= 60) {
            return $name;
        }

        return substr($schema, 0, 28).'_'.substr($suffix, 0, 20).'_'.substr(sha1($name), 0, 8);
    }

    private function provisionSqliteAttachedSchema(string $schema): void
    {
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $schema)) {
            throw new \InvalidArgumentException('Invalid schema name.');
        }

        try {
            DB::statement(sprintf('ATTACH DATABASE \':memory:\' AS "%s"', $schema));
        } catch (\Throwable) {
            // SQLite keeps the attached database for the connection lifetime.
        }

        DB::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS "%s"."configuracion_facturacion" (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ruc_sol VARCHAR(11) NULL,
                usuario_sol VARCHAR(120) NULL,
                clave_sol_encrypted TEXT NULL,
                certificado_url VARCHAR(500) NULL,
                certificado_password TEXT NULL,
                modo_sunat VARCHAR(20) NULL,
                logo_pdf_url VARCHAR(500) NULL,
                serie_factura VARCHAR(10) NULL,
                serie_boleta VARCHAR(10) NULL,
                serie_nc VARCHAR(10) NULL,
                serie_nd VARCHAR(10) NULL,
                serie_guia VARCHAR(10) NULL,
                igv NUMERIC NULL,
                moneda VARCHAR(3) NULL,
                token_api VARCHAR(255) NULL,
                cuentas_bancarias TEXT NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )',
            $schema,
        ));
    }
}
