<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('external_tenant_id', 80)->nullable()->unique();
            $table->string('country_code', 2)->default('PE');
            $table->string('tax_id', 40)->nullable();
            $table->string('document_mode', 30)->default('ticket_only');
            $table->string('fiscal_status', 30)->default('not_configured');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE public.tenants ALTER COLUMN ruc TYPE VARCHAR(40)');
            DB::statement("ALTER TABLE public.tenants ALTER COLUMN sunat_mode SET DEFAULT 'disabled'");
        } elseif (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE tenants MODIFY ruc VARCHAR(40) NOT NULL');
            DB::statement("ALTER TABLE tenants ALTER sunat_mode SET DEFAULT 'disabled'");
        }

        DB::table('tenants')
            ->whereNull('tax_id')
            ->update(['tax_id' => DB::raw('ruc')]);

        DB::table('tenants')
            ->whereIn('sunat_mode', ['beta', 'production'])
            ->update([
                'document_mode' => 'electronic',
                'fiscal_status' => 'active',
            ]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropUnique(['external_tenant_id']);
            $table->dropColumn([
                'external_tenant_id',
                'country_code',
                'tax_id',
                'document_mode',
                'fiscal_status',
            ]);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE public.tenants ALTER COLUMN sunat_mode SET DEFAULT 'beta'");
        }
    }
};
