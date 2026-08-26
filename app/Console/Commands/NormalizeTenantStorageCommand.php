<?php

namespace App\Console\Commands;

use App\Infrastructure\Tenant\TenantArtifactStorage;
use App\Infrastructure\Tenant\TenantStoragePathResolver;
use Illuminate\Console\Command;

/**
 * Reunifica los ficheros del tenant bajo una unica convencion de rutas.
 *
 * El disco "tenants" ya tiene su raiz en storage/app/tenants, pero durante un
 * tiempo el resolver anteponia otra vez "tenants/". El resultado fue que los
 * comprobantes acabaron en tenants/tenants/{ruc} mientras los certificados y
 * logos estaban en tenants/{ruc}: dos arboles paralelos que ningun backup ni
 * migracion a S3 cubria a la vez.
 */
final class NormalizeTenantStorageCommand extends Command
{
    protected $signature = 'facturador:storage:normalizar
        {--dry-run : Solo muestra lo que se moveria}
        {--force : No pide confirmacion}';

    protected $description = 'Mueve los archivos del tenant de la ruta duplicada tenants/tenants/{ruc} a tenants/{ruc}';

    public function handle(TenantArtifactStorage $storage): int
    {
        $disk = $storage->disk();
        $prefix = TenantStoragePathResolver::legacyPrefix();

        $legacyFiles = $disk->allFiles(rtrim($prefix, '/'));

        if ($legacyFiles === []) {
            $this->info('No hay archivos en la ruta antigua: nada que mover.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Archivos en la ruta antigua: %d', count($legacyFiles)));

        if ($this->option('dry-run')) {
            foreach ($legacyFiles as $file) {
                $this->line(sprintf('  %s  ->  %s', $file, $this->canonicalPath($file, $prefix)));
            }

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Mover los archivos a la ruta canonica?', true)) {
            $this->warn('Operacion cancelada.');

            return self::SUCCESS;
        }

        $moved = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($legacyFiles as $file) {
            $target = $this->canonicalPath($file, $prefix);

            // Si ya existe en destino, el de la ruta canonica es el vigente:
            // nunca se sobrescribe un comprobante ya emitido.
            if ($disk->exists($target)) {
                $skipped++;

                continue;
            }

            try {
                $disk->move($file, $target);
                $moved++;
            } catch (\Throwable $exception) {
                $failed++;
                $this->error(sprintf('  %s: %s', $file, $exception->getMessage()));
            }
        }

        $this->info(sprintf('Movidos: %d | Ya existentes en destino: %d | Fallidos: %d', $moved, $skipped, $failed));

        if ($failed > 0) {
            return self::FAILURE;
        }

        $this->line('Revisa que la lectura funcione y elimina despues el directorio '.$prefix.' vacio.');

        return self::SUCCESS;
    }

    private function canonicalPath(string $legacyPath, string $prefix): string
    {
        return substr($legacyPath, strlen($prefix));
    }
}
