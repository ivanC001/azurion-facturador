<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra /api/documentation y /docs fuera de los entornos de desarrollo.
 *
 * Venian sin ningun middleware, de modo que cualquiera podia enumerar todos
 * los endpoints, cabeceras y esquemas de la API en produccion.
 */
final class RestrictApiDocumentationMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isAccessible()) {
            return $next($request);
        }

        abort(404);
    }

    private function isAccessible(): bool
    {
        if ((bool) config('facturador.docs.public', false)) {
            return true;
        }

        return app()->environment('local', 'testing');
    }
}
