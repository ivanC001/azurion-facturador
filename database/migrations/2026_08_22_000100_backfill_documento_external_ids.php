<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normaliza el external_id historico de cada tenant.
 *
 * Estas sentencias vivian dentro de TenantSchemaManager::provision(), que se
 * reejecuta cada vez que se vacia la cache de provisionamiento. Eso convertia
 * una migracion de datos de un solo uso en un UPDATE destructivo recurrente
 * sobre documentos fiscales. Aqui corre una vez y queda registrada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tenantSchemas() as $schema) {
            // Sube el external_id del payload a su columna dedicada.
            DB::statement(sprintf(
                'UPDATE "%s"."documentos"
                    SET external_id = NULLIF(BTRIM(payload->\'documento\'->>\'external_id\'), \'\')
                  WHERE external_id IS NULL',
                $schema,
            ));

            // El indice unico solo admite un documento por external_id: se
            // conserva el primero emitido y se libera la columna del resto.
            DB::statement(sprintf(
                'WITH ranked AS (
                    SELECT id,
                           ROW_NUMBER() OVER (PARTITION BY external_id ORDER BY id) AS position
                      FROM "%s"."documentos"
                     WHERE external_id IS NOT NULL
                )
                UPDATE "%s"."documentos" documento
                   SET external_id = NULL
                  FROM ranked
                 WHERE documento.id = ranked.id
                   AND ranked.position > 1',
                $schema,
                $schema,
            ));
        }
    }

    public function down(): void
    {
        // El external_id sigue disponible dentro del payload; no se restaura
        // nada de forma automatica sobre documentos ya declarados.
    }

    /**
     * @return list<string>
     */
    private function tenantSchemas(): array
    {
        $schemas = [];

        foreach (DB::table('tenants')->pluck('schema_name') as $schema) {
            if (! is_string($schema) || ! preg_match('/^[a-zA-Z0-9_]+$/', $schema)) {
                continue;
            }

            $exists = DB::selectOne('SELECT to_regclass(?) AS table_name', [$schema.'.documentos']);
            if (($exists->table_name ?? null) === null) {
                continue;
            }

            $schemas[] = $schema;
        }

        return $schemas;
    }
};
