<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$db = Illuminate\Support\Facades\DB::class;

$jobs = Illuminate\Support\Facades\DB::table('jobs')->select('id', 'queue', 'attempts')->orderBy('id')->get();
echo 'jobs_pending='.count($jobs).PHP_EOL;
foreach ($jobs as $job) {
    echo 'job#'.$job->id.' queue='.$job->queue.' attempts='.$job->attempts.PHP_EOL;
}

$tenants = Illuminate\Support\Facades\DB::table('tenants')->select('id', 'ruc', 'schema_name')->orderBy('id')->get();
echo 'tenants='.count($tenants).PHP_EOL;

foreach ($tenants as $tenant) {
    echo 'tenant '.$tenant->id.' ruc='.$tenant->ruc.' schema='.$tenant->schema_name.PHP_EOL;
    Illuminate\Support\Facades\DB::statement('SET search_path TO '.$tenant->schema_name.', public');

    $rows = Illuminate\Support\Facades\DB::table('documentos')
        ->select('estado', Illuminate\Support\Facades\DB::raw('count(*) as total'))
        ->groupBy('estado')
        ->orderBy('estado')
        ->get();

    if ($rows->isEmpty()) {
        echo '  documentos=0'.PHP_EOL;
        continue;
    }

    foreach ($rows as $row) {
        echo '  estado='.$row->estado.' total='.$row->total.PHP_EOL;
    }
}

