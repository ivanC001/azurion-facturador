<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PublicCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('public.tipos_documento')->upsert([
            ['codigo' => '01', 'descripcion' => 'Factura', 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => '03', 'descripcion' => 'Boleta', 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => '07', 'descripcion' => 'Nota de credito', 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => '08', 'descripcion' => 'Nota de debito', 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => '09', 'descripcion' => 'Guia de remision', 'created_at' => now(), 'updated_at' => now()],
        ], ['codigo'], ['descripcion', 'updated_at']);

        DB::table('public.monedas')->upsert([
            ['codigo' => 'PEN', 'descripcion' => 'Sol', 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => 'USD', 'descripcion' => 'Dolar estadounidense', 'created_at' => now(), 'updated_at' => now()],
        ], ['codigo'], ['descripcion', 'updated_at']);

        DB::table('public.tipos_tributos')->upsert([
            ['codigo' => '1000', 'descripcion' => 'IGV', 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => '2000', 'descripcion' => 'ISC', 'created_at' => now(), 'updated_at' => now()],
        ], ['codigo'], ['descripcion', 'updated_at']);

        DB::table('public.catalogos_sunat')->upsert([
            ['catalogo' => '07', 'codigo' => '10', 'descripcion' => 'Gravado - Operacion Onerosa', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['catalogo' => '07', 'codigo' => '11', 'descripcion' => 'Gravado - Retiro por premio', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['catalogo' => '07', 'codigo' => '12', 'descripcion' => 'Gravado - Retiro por donacion', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['catalogo' => '07', 'codigo' => '13', 'descripcion' => 'Gravado - Retiro', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['catalogo' => '07', 'codigo' => '14', 'descripcion' => 'Gravado - Retiro por publicidad', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['catalogo' => '07', 'codigo' => '15', 'descripcion' => 'Gravado - Bonificaciones', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['catalogo' => '07', 'codigo' => '16', 'descripcion' => 'Gravado - Retiro por entrega a trabajadores', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['catalogo' => '07', 'codigo' => '17', 'descripcion' => 'Gravado - IVAP', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['catalogo' => '07', 'codigo' => '20', 'descripcion' => 'Exonerado - Operacion Onerosa', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['catalogo' => '07', 'codigo' => '21', 'descripcion' => 'Exonerado - Transferencia Gratuita', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['catalogo' => '07', 'codigo' => '30', 'descripcion' => 'Inafecto - Operacion Onerosa', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['catalogo' => '07', 'codigo' => '31', 'descripcion' => 'Inafecto - Retiro por Bonificacion', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['catalogo' => '07', 'codigo' => '32', 'descripcion' => 'Inafecto - Retiro', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['catalogo' => '07', 'codigo' => '33', 'descripcion' => 'Inafecto - Retiro por Muestras Medicas', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['catalogo' => '07', 'codigo' => '34', 'descripcion' => 'Inafecto - Retiro por Convenio Colectivo', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['catalogo' => '07', 'codigo' => '35', 'descripcion' => 'Inafecto - Retiro por premio', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['catalogo' => '07', 'codigo' => '36', 'descripcion' => 'Inafecto - Retiro por publicidad', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['catalogo' => '07', 'codigo' => '40', 'descripcion' => 'Exportacion', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ], ['catalogo', 'codigo'], ['descripcion', 'is_active', 'updated_at']);
    }
}
