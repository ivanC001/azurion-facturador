<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PublicCatalogSeeder::class,
        ]);

        $this->seedDevelopmentAdmin();
    }

    /**
     * Crea un administrador de plataforma para desarrollo local.
     *
     * Nunca en produccion y nunca con contrasena fija: este seeder llego a
     * dejar un administrador con credenciales publicadas en el repositorio.
     * Para dar de alta un administrador real usa `facturador:platform-admin`.
     */
    private function seedDevelopmentAdmin(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->warn(
                'Seeder de administrador omitido fuera de local/testing. '
                .'Usa "php artisan facturador:platform-admin <email>" para crearlo.',
            );

            return;
        }

        $email = (string) env('FACTURADOR_SEED_ADMIN_EMAIL', 'admin@azurion.test');
        $password = (string) env('FACTURADOR_SEED_ADMIN_PASSWORD', '');
        $generated = $password === '';

        if ($generated) {
            $password = Str::password(20);
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $user->name ?? 'Administrador local',
            'password' => $password,
            'tenant_id' => null,
            'is_platform_admin' => true,
        ])->save();

        if ($generated) {
            $this->command?->info(sprintf(
                'Administrador local: %s / %s (guardala, no se vuelve a mostrar)',
                $email,
                $password,
            ));
        }
    }
}
