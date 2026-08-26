<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resumen del estado de la cola SUNAT y de los documentos por tenant.
 *
 * Sustituye a scripts/diagnose_sunat_queue.php, que interpolaba el nombre del
 * esquema en un SET search_path sin validarlo y dejaba la conexion apuntando
 * al ultimo tenant recorrido.
 */
final class DiagnoseSunatQueueCommand extends Command
{
    protected $signature = 'facturador:diagnostico:cola {--tenant= : Limita el diagnostico a un RUC}';

    protected $description = 'Muestra los jobs pendientes y el estado de los documentos de cada tenant';

    public function handle(): int
    {
        $this->renderPendingJobs();

        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($query, $ruc) => $query->where('ruc', $ruc))
            ->orderBy('id')
            ->get(['id', 'ruc', 'schema_name']);

        $this->newLine();
        $this->info(sprintf('Tenants: %d', $tenants->count()));

        foreach ($tenants as $tenant) {
            $this->renderTenant($tenant);
        }

        return self::SUCCESS;
    }

    private function renderPendingJobs(): void
    {
        try {
            $jobs = DB::table('jobs')->orderBy('id')->get(['id', 'queue', 'attempts']);
        } catch (\Throwable) {
            $this->warn('No hay tabla "jobs": la cola no usa el driver de base de datos.');

            return;
        }

        $this->info(sprintf('Jobs pendientes: %d', $jobs->count()));

        if ($jobs->isEmpty()) {
            return;
        }

        $this->table(
            ['ID', 'Cola', 'Intentos'],
            $jobs->map(fn (object $job): array => [$job->id, $job->queue, $job->attempts])->all(),
        );
    }

    private function renderTenant(Tenant $tenant): void
    {
        $schema = (string) $tenant->schema_name;

        $this->newLine();
        $this->line(sprintf('Tenant %d - RUC %s - schema %s', $tenant->id, $tenant->ruc, $schema));

        if (preg_match('/^[a-zA-Z0-9_]+$/', $schema) !== 1) {
            $this->error('  Nombre de esquema invalido; se omite.');

            return;
        }

        $usesPostgres = Str::contains((string) DB::connection()->getDriverName(), 'pgsql');

        try {
            if ($usesPostgres) {
                DB::statement(sprintf('SET search_path TO "%s", public', $schema));
            }

            $rows = DB::table('documentos')
                ->select('estado', DB::raw('count(*) as total'))
                ->groupBy('estado')
                ->orderBy('estado')
                ->get();

            if ($rows->isEmpty()) {
                $this->line('  documentos = 0');

                return;
            }

            foreach ($rows as $row) {
                $this->line(sprintf('  %-14s %d', $row->estado, $row->total));
            }
        } catch (\Throwable $exception) {
            $this->error('  '.$exception->getMessage());
        } finally {
            // Se restaura siempre: si no, el resto del comando -- y cualquier
            // consulta posterior -- leeria del esquema del ultimo tenant.
            if ($usesPostgres) {
                DB::statement('SET search_path TO public');
            }
        }
    }
}
