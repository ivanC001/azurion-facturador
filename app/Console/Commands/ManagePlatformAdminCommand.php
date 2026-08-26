<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Unica via prevista para conceder o retirar el rol de administrador del
 * facturador. Existe para que el privilegio se otorgue de forma deliberada
 * y quede fuera de seeders, factories y payloads de la API.
 */
final class ManagePlatformAdminCommand extends Command
{
    protected $signature = 'facturador:platform-admin
        {email : Correo del usuario}
        {--name= : Nombre a usar si el usuario aun no existe}
        {--revoke : Retira el rol en lugar de concederlo}';

    protected $description = 'Concede o retira el rol de administrador de plataforma a un usuario';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('El correo indicado no es valido.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($this->option('revoke')) {
            return $this->revoke($user, $email);
        }

        return $this->grant($user, $email);
    }

    private function grant(?User $user, string $email): int
    {
        $generatedPassword = null;

        if ($user === null) {
            $generatedPassword = Str::password(20);
            $user = new User;
            $user->email = $email;
            $user->name = (string) ($this->option('name') ?: 'Administrador del facturador');
            $user->password = $generatedPassword;
        }

        $user->forceFill([
            'tenant_id' => null,
            'is_platform_admin' => true,
        ])->save();

        $this->info('Rol de administrador concedido a '.$email.'.');

        if ($generatedPassword !== null) {
            $this->warn('Contrasena generada: '.$generatedPassword);
            $this->warn('Guardala ahora: no vuelve a mostrarse y no queda registrada en los logs.');
        }

        return self::SUCCESS;
    }

    private function revoke(?User $user, string $email): int
    {
        if ($user === null) {
            $this->error('No existe ningun usuario con el correo '.$email.'.');

            return self::FAILURE;
        }

        $user->forceFill(['is_platform_admin' => false])->save();

        $this->info('Rol de administrador retirado a '.$email.'.');
        $this->line('El usuario no podra iniciar sesion hasta que se le asigne un tenant.');

        return self::SUCCESS;
    }
}
