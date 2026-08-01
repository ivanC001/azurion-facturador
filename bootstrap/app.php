<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.api' => App\Http\Middleware\ApiAuthenticationMiddleware::class,
            'azurion.integration' => App\Http\Middleware\RequireAzurionIntegrationMiddleware::class,
            'facturador.management' => App\Http\Middleware\RequireFacturadorManagementMiddleware::class,
            'resolve.tenant' => App\Http\Middleware\ResolveTenantMiddleware::class,
            'tenant.search_path' => App\Http\Middleware\SetTenantSearchPathMiddleware::class,
        ]);
        $middleware->appendToGroup('api', [
            App\Http\Middleware\AuditApiRequestMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, \Throwable $e): bool {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'code' => class_basename($e::class),
                    'status' => 422,
                    'errors' => $e->errors(),
                ], 422);
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            return response()->json([
                'message' => $status === 500 ? 'Unexpected server error.' : $e->getMessage(),
                'code' => class_basename($e::class),
                'status' => $status,
            ], $status);
        });
    })->create();
