<?php

namespace App\Http\Controllers\Api;

use App\Application\Sucursales\UseCases\DeleteSucursalUseCase;
use App\Application\Sucursales\UseCases\ListSucursalesUseCase;
use App\Application\Sucursales\UseCases\SaveSucursalUseCase;
use App\Support\ApiResponse;
use App\Support\Ubigeos\UbigeoCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SucursalController
{
    public function __construct(
        private readonly ListSucursalesUseCase $listSucursalesUseCase,
        private readonly SaveSucursalUseCase $saveSucursalUseCase,
        private readonly DeleteSucursalUseCase $deleteSucursalUseCase,
        private readonly UbigeoCatalog $ubigeoCatalog,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->listSucursalesUseCase->execute());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request, true);

        return ApiResponse::success($this->saveSucursalUseCase->create($data), 201);
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success($this->saveSucursalUseCase->find($id));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $this->validatePayload($request, false);

        return ApiResponse::success($this->saveSucursalUseCase->update($id, $data));
    }

    public function destroy(int $id): JsonResponse
    {
        return ApiResponse::success($this->deleteSucursalUseCase->execute($id));
    }

    private function validatePayload(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'codigo' => [$required, 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/'],
            'nombre' => [$required, 'string', 'max:180'],
            'direccion' => ['nullable', 'string', 'max:500'],
            'ubigeo' => ['nullable', 'string', 'size:6'],
            'departamento' => ['nullable', 'string', 'max:80'],
            'provincia' => ['nullable', 'string', 'max:80'],
            'distrito' => ['nullable', 'string', 'max:80'],
            'cod_local' => ['nullable', 'string', 'max:10'],
            'is_active' => ['sometimes', 'boolean'],
            'series' => ['sometimes', 'array'],
            'series.*.tipo_documento' => ['required_with:series', Rule::in(['01', '03', '07', '08', '09', 'TK'])],
            'series.*.serie' => ['required_with:series', 'string', 'max:10'],
            'series.*.correlativo_actual' => ['sometimes', 'integer', 'min:0'],
            'series.*.is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('ubigeo', $data) && $data['ubigeo'] !== null) {
            $normalized = $this->ubigeoCatalog->normalize((string) $data['ubigeo']);

            abort_unless($normalized !== null, 422, 'Ubigeo de sucursal no es valido o no existe en el catalogo.');

            $data['ubigeo'] = $normalized;
        }

        return $data;
    }
}
