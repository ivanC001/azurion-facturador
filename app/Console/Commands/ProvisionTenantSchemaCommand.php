<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Infrastructure\Tenant\TenantSchemaManager;
use Illuminate\Console\Command;

class ProvisionTenantSchemaCommand extends Command
{
    protected $signature = 'tenant:provision {ruc : RUC del tenant} {--schema= : Schema personalizado}';

    protected $description = 'Crea schema y tablas base del tenant en PostgreSQL';

    public function handle(TenantSchemaManager $tenantSchemaManager): int
    {
        $ruc = (string) $this->argument('ruc');
        $schema = (string) ($this->option('schema') ?: 'empresa_'.$ruc);

        $tenant = Tenant::query()->firstWhere('ruc', $ruc);

        if ($tenant !== null && $tenant->schema_name !== $schema) {
            $this->error('El tenant ya existe con otro schema: '.$tenant->schema_name);

            return self::FAILURE;
        }

        $tenantSchemaManager->provision($schema);

        $this->info('Schema provisionado: '.$schema);

        return self::SUCCESS;
    }
}
