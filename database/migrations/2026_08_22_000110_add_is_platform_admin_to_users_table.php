<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convierte el rol de administrador de plataforma en un dato explicito.
 *
 * Hasta ahora se deducia de "tenant_id IS NULL", asi que cualquier usuario
 * creado sin tenant -- incluido el del seeder o el de una factory -- obtenia
 * permisos sobre todos los tenants sin que nadie lo hubiese concedido.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'is_platform_admin')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_platform_admin')->default(false)->index();
        });

        // Los administradores que ya operaban conservan su acceso: la regla
        // implicita anterior se materializa una sola vez en la columna.
        DB::table($this->usersTable())
            ->whereNull('tenant_id')
            ->update(['is_platform_admin' => true]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'is_platform_admin')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_platform_admin');
        });
    }

    private function usersTable(): string
    {
        return DB::getDriverName() === 'pgsql' ? 'public.users' : 'users';
    }
};
