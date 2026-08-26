<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AzurionTenantManagementController;
use App\Http\Controllers\Api\AzurionTenantProvisioningController;
use App\Http\Controllers\Api\DocumentoController;
use App\Http\Controllers\Api\SucursalController;
use App\Http\Controllers\Api\SunatController;
use App\Http\Controllers\Api\TenantController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:api');
});

Route::prefix('tenants')->middleware(['throttle:api', 'auth.api', 'facturador.management'])->group(function (): void {
    Route::get('/', [TenantController::class, 'index']);
    Route::post('/', [TenantController::class, 'store']);
    Route::get('/{id}/sucursales', [TenantController::class, 'sucursales']);
    Route::get('/{id}', [TenantController::class, 'show']);
    Route::put('/{id}', [TenantController::class, 'update']);
    Route::patch('/{id}', [TenantController::class, 'update']);
    Route::delete('/{id}', [TenantController::class, 'destroy']);
});

Route::put('/integrations/azurion/tenants/{externalTenantId}', [AzurionTenantProvisioningController::class, 'upsert'])
    ->where('externalTenantId', '[A-Za-z0-9._:-]{2,80}')
    ->middleware(['throttle:api', 'auth.api', 'azurion.integration']);

Route::prefix('/integrations/azurion/management')
    ->middleware(['throttle:api', 'auth.api', 'azurion.integration'])
    ->group(function (): void {
        Route::get('/tenants', [AzurionTenantManagementController::class, 'index']);
        Route::post('/tenants', [AzurionTenantManagementController::class, 'store']);
        Route::get('/tenants/external/{externalTenantId}', [AzurionTenantManagementController::class, 'showExternal'])
            ->where('externalTenantId', '[A-Za-z0-9._:-]{2,80}');
        Route::put('/tenants/external/{externalTenantId}', [AzurionTenantManagementController::class, 'updateExternal'])
            ->where('externalTenantId', '[A-Za-z0-9._:-]{2,80}');
        Route::get('/tenants/{id}', [AzurionTenantManagementController::class, 'show'])->whereNumber('id');
        Route::put('/tenants/{id}', [AzurionTenantManagementController::class, 'update'])->whereNumber('id');
    });

Route::middleware(['throttle:api', 'signed', 'resolve.tenant', 'tenant.search_path'])->group(function (): void {
    Route::get('/documentos/{id}/pdf', [DocumentoController::class, 'pdf'])->name('documentos.pdf');
    Route::get('/documentos/{id}/xml', [DocumentoController::class, 'xml'])->name('documentos.xml');
    Route::get('/documentos/{id}/cdr', [DocumentoController::class, 'cdr'])->name('documentos.cdr');
});

Route::middleware(['throttle:api', 'auth.api', 'resolve.tenant', 'tenant.search_path'])->group(function (): void {
    Route::apiResource('sucursales', SucursalController::class);

    Route::get('/documentos', [DocumentoController::class, 'index']);
    Route::post('/documentos', [DocumentoController::class, 'store']);
    Route::post('/facturas', [DocumentoController::class, 'store']);
    Route::post('/boletas', [DocumentoController::class, 'storeBoleta']);
    Route::post('/tickets', [DocumentoController::class, 'storeTicket']);
    Route::post('/notas-credito', [DocumentoController::class, 'storeNotaCredito']);
    Route::post('/notas-debito', [DocumentoController::class, 'storeNotaDebito']);
    Route::post('/guias', [DocumentoController::class, 'storeGuia']);
    Route::post('/resumenes', [DocumentoController::class, 'storeResumen']);

    Route::get('/documentos/{id}', [DocumentoController::class, 'show']);
    Route::post('/sunat/enviar', [SunatController::class, 'enviar']);
    Route::get('/sunat/estado', [SunatController::class, 'estado']);
});
