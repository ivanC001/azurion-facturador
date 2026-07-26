<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('ruc', 11)->unique();
            $table->string('business_name');
            $table->string('schema_name', 120)->unique();
            $table->string('sunat_mode', 20)->default('beta');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('api_clients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('api_key_hash', 128)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('catalogos_sunat', function (Blueprint $table): void {
            $table->id();
            $table->string('catalogo', 20);
            $table->string('codigo', 20);
            $table->string('descripcion');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['catalogo', 'codigo']);
        });

        Schema::create('tipos_documento', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 4)->unique();
            $table->string('descripcion');
            $table->timestamps();
        });

        Schema::create('tipos_tributos', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 6)->unique();
            $table->string('descripcion');
            $table->timestamps();
        });

        Schema::create('monedas', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 3)->unique();
            $table->string('descripcion');
            $table->timestamps();
        });

        Schema::create('ubigeos', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 6)->unique();
            $table->string('departamento');
            $table->string('provincia');
            $table->string('distrito');
            $table->timestamps();
        });
        $this->seedUbigeosFromCsv();

        Schema::create('paises', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 3)->unique();
            $table->string('descripcion');
            $table->timestamps();
        });

        Schema::create('unidades_medida', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 6)->unique();
            $table->string('descripcion');
            $table->timestamps();
        });

        Schema::create('configuraciones_globales', function (Blueprint $table): void {
            $table->id();
            $table->string('clave', 120)->unique();
            $table->text('valor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones_globales');
        Schema::dropIfExists('unidades_medida');
        Schema::dropIfExists('paises');
        Schema::dropIfExists('ubigeos');
        Schema::dropIfExists('monedas');
        Schema::dropIfExists('tipos_tributos');
        Schema::dropIfExists('tipos_documento');
        Schema::dropIfExists('catalogos_sunat');
        Schema::dropIfExists('api_clients');
        Schema::dropIfExists('tenants');
    }

    private function seedUbigeosFromCsv(): void
    {
        $path = database_path('seeders/data/equivalencia-ubigeos-oti-concytec.utf8.csv');
        if (! is_file($path) || ! is_readable($path)) {
            return;
        }

        $handle = fopen($path, 'rb');
        if (! is_resource($handle)) {
            return;
        }

        try {
            $header = fgetcsv($handle);
            if (! is_array($header)) {
                return;
            }

            $header = array_map([$this, 'normalizeHeader'], $header);
            $idxCodigo = array_search('cod_ubigeo_sunat', $header, true);
            $idxDepartamento = array_search('desc_dep_sunat', $header, true);
            $idxProvincia = array_search('desc_prov_sunat', $header, true);
            $idxDistrito = array_search('desc_ubigeo_sunat', $header, true);

            if ($idxCodigo === false || $idxDepartamento === false || $idxProvincia === false || $idxDistrito === false) {
                return;
            }

            $rows = [];
            while (($row = fgetcsv($handle)) !== false) {
                if (! is_array($row)) {
                    continue;
                }

                $codigo = $this->sanitizeUbigeoCode((string) ($row[$idxCodigo] ?? ''));
                if ($codigo === null) {
                    continue;
                }

                $rows[$codigo] = [
                    'codigo' => $codigo,
                    'departamento' => trim((string) ($row[$idxDepartamento] ?? '')),
                    'provincia' => trim((string) ($row[$idxProvincia] ?? '')),
                    'distrito' => trim((string) ($row[$idxDistrito] ?? '')),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows === []) {
                return;
            }

            DB::table('ubigeos')->upsert(
                array_values($rows),
                ['codigo'],
                ['departamento', 'provincia', 'distrito', 'updated_at'],
            );
        } finally {
            fclose($handle);
        }
    }

    private function normalizeHeader(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;

        return trim($value, "\" \t\n\r\0\x0B");
    }

    private function sanitizeUbigeoCode(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) < 6) {
            return null;
        }

        $digits = substr($digits, 0, 6);

        return strlen($digits) === 6 ? $digits : null;
    }
};
