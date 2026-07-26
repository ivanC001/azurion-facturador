<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

$key = 'azf_test_20260530_integracion';
$reg = DB::selectOne("select to_regclass('public.tenants') as reg");

if ($reg === null || $reg->reg === null) {
    echo "tenants_table_missing\n";
    exit(0);
}

$tenants = Tenant::query()->where('is_active', true)->get();
foreach ($tenants as $tenant) {
    DB::table('public.api_clients')->updateOrInsert(
        ['tenant_id' => $tenant->id, 'name' => 'azurion-erp'],
        ['api_key_hash' => hash('sha256', $key), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
    );

    $schema = $tenant->schema_name;
    if (preg_match('/^[a-zA-Z0-9_]+$/', $schema) === 1) {
        $regCfg = DB::selectOne("select to_regclass('{$schema}.configuracion_facturacion') as reg");
        if ($regCfg !== null && $regCfg->reg !== null) {
            DB::table($schema.'.configuracion_facturacion')->updateOrInsert(
                ['id' => 1],
                ['token_api' => $key, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }
}

echo 'api_key_synced:'.$tenants->count()."\n";
