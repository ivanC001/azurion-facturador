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

            $qualifiedTable = sprintf('"%s"."configuracion_facturacion"', $schema);
            $exists = DB::selectOne('SELECT to_regclass(?) AS table_name', [$schema.'.configuracion_facturacion']);
            if (($exists->table_name ?? null) === null) {
                continue;
            }

            DB::statement(sprintf(
                'UPDATE %s SET certificado_password = NULL WHERE certificado_password IS NOT NULL',
                $qualifiedTable,
            ));
        }
    }

    public function down(): void
    {
        // Las contrasenas eliminadas no deben recuperarse.
    }
};
