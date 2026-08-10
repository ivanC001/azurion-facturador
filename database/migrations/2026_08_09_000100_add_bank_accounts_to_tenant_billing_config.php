<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (DB::table('tenants')->pluck('schema_name') as $schema) {
            if (! is_string($schema) || ! preg_match('/^[a-zA-Z0-9_]+$/', $schema)) {
                continue;
            }

            $exists = DB::selectOne('SELECT to_regclass(?) AS table_name', [$schema.'.configuracion_facturacion']);
            if (($exists->table_name ?? null) === null) {
                continue;
            }

            DB::statement(sprintf(
                'ALTER TABLE "%s"."configuracion_facturacion" ADD COLUMN IF NOT EXISTS cuentas_bancarias JSONB',
                $schema,
            ));
        }
    }

    public function down(): void
    {
        // Se conserva el dato para no eliminar cuentas configuradas por cada tenant.
    }
};
