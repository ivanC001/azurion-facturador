<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => 'Azurion Facturador API',
        'status' => 'ok',
    ]);
});
