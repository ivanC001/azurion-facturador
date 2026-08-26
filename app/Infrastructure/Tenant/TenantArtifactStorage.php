<?php

namespace App\Infrastructure\Tenant;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unico punto de acceso al disco privado de los tenants.
 *
 * Centraliza dos cosas que estaban repetidas por toda la aplicacion: la
 * resolucion del disco configurado y la lectura de ficheros que aun estan en
 * la ruta antigua tenants/{ruc}/... Las escrituras siempre usan la ruta
 * canonica, de modo que el respaldo se va vaciando solo.
 */
final class TenantArtifactStorage
{
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('facturador.storage.disk', 'tenants'));
    }

    public function exists(string $path): bool
    {
        return $this->readablePath($path) !== null;
    }

    /**
     * Devuelve la ruta donde el fichero esta realmente disponible, o null.
     */
    public function readablePath(string $path): ?string
    {
        $disk = $this->disk();

        if ($disk->exists($path)) {
            return $path;
        }

        $legacy = TenantStoragePathResolver::legacyPathFor($path);

        return $disk->exists($legacy) ? $legacy : null;
    }

    public function get(string $path): ?string
    {
        $readable = $this->readablePath($path);

        return $readable === null ? null : (string) $this->disk()->get($readable);
    }

    public function put(string $path, string $contents): void
    {
        $this->disk()->put($path, $contents);
    }

    public function putUploadedFile(string $directory, UploadedFile $file, string $fileName): void
    {
        $this->disk()->putFileAs($directory, $file, $fileName);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function download(string $path, array $headers = []): StreamedResponse
    {
        $readable = $this->readablePath($path);

        abort_if($readable === null, 404, 'El archivo solicitado no esta disponible.');

        return $this->disk()->download($readable, basename($readable), $headers);
    }
}
