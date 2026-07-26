<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ImportUbigeoEquivalenceCommand extends Command
{
    protected $signature = 'ubigeos:import {path? : Ruta del CSV de equivalencias}';

    protected $description = 'Importa ubigeos SUNAT (codigo/departamento/provincia/distrito) desde CSV de equivalencias.';

    public function handle(): int
    {
        $path = trim((string) ($this->argument('path') ?: config('facturador.ubigeos.equivalences_csv_path', '')));
        if ($path === '') {
            $this->error('Debes indicar la ruta del CSV o configurar UBIGEO_EQUIVALENCES_CSV_PATH.');

            return self::FAILURE;
        }

        if (! is_file($path) || ! is_readable($path)) {
            $this->error('No se puede leer el archivo: '.$path);

            return self::FAILURE;
        }

        $handle = fopen($path, 'rb');
        if (! is_resource($handle)) {
            $this->error('No se pudo abrir el archivo CSV.');

            return self::FAILURE;
        }

        try {
            $header = fgetcsv($handle);
            if (! is_array($header)) {
                $this->error('CSV sin encabezado valido.');

                return self::FAILURE;
            }

            $header = array_map([$this, 'normalizeHeader'], $header);
            $idx = [
                'codigo' => array_search('cod_ubigeo_sunat', $header, true),
                'departamento' => array_search('desc_dep_sunat', $header, true),
                'provincia' => array_search('desc_prov_sunat', $header, true),
                'distrito' => array_search('desc_ubigeo_sunat', $header, true),
            ];

            foreach ($idx as $key => $position) {
                if ($position === false) {
                    $this->error('No se encontro columna requerida: '.$key);

                    return self::FAILURE;
                }
            }

            $rows = [];
            while (($row = fgetcsv($handle)) !== false) {
                if (! is_array($row)) {
                    continue;
                }

                $codigo = $this->sanitizeCode((string) ($row[$idx['codigo']] ?? ''));
                if ($codigo === null) {
                    continue;
                }

                $rows[$codigo] = [
                    'codigo' => $codigo,
                    'departamento' => trim((string) ($row[$idx['departamento']] ?? '')),
                    'provincia' => trim((string) ($row[$idx['provincia']] ?? '')),
                    'distrito' => trim((string) ($row[$idx['distrito']] ?? '')),
                    'updated_at' => now(),
                    'created_at' => now(),
                ];
            }

            if ($rows === []) {
                $this->warn('No se encontraron filas utiles para importar.');

                return self::SUCCESS;
            }

            try {
                DB::table('ubigeos')->upsert(
                    array_values($rows),
                    ['codigo'],
                    ['departamento', 'provincia', 'distrito', 'updated_at'],
                );
            } catch (QueryException $exception) {
                $message = $exception->getMessage();
                if (str_contains(strtolower($message), 'ubigeos')) {
                    $this->error('La tabla public.ubigeos no existe. Ejecuta primero: php artisan migrate');

                    return self::FAILURE;
                }

                throw $exception;
            }

            $this->info('Ubigeos importados/actualizados: '.count($rows));

            return self::SUCCESS;
        } finally {
            fclose($handle);
        }
    }

    private function normalizeHeader(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;

        return trim($value, "\" \t\n\r\0\x0B");
    }

    private function sanitizeCode(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) < 6) {
            return null;
        }

        $digits = substr($digits, 0, 6);

        return strlen($digits) === 6 ? $digits : null;
    }
}
