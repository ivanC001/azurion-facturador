<?php

namespace App\Http\Controllers\Api;

use App\Application\Tenants\UseCases\DeleteTenantUseCase;
use App\Application\Tenants\UseCases\ListTenantSucursalesUseCase;
use App\Application\Tenants\UseCases\ListTenantsUseCase;
use App\Application\Tenants\UseCases\RegisterTenantUseCase;
use App\Application\Tenants\UseCases\ShowTenantUseCase;
use App\Application\Tenants\UseCases\UpdateTenantUseCase;
use App\Support\ApiResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class TenantController
{
    public function __construct(
        private readonly RegisterTenantUseCase $registerTenantUseCase,
        private readonly ListTenantsUseCase $listTenantsUseCase,
        private readonly ListTenantSucursalesUseCase $listTenantSucursalesUseCase,
        private readonly ShowTenantUseCase $showTenantUseCase,
        private readonly UpdateTenantUseCase $updateTenantUseCase,
        private readonly DeleteTenantUseCase $deleteTenantUseCase,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->listTenantsUseCase->execute());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ruc' => ['required', 'string', 'size:11', 'regex:/^[0-9]{11}$/'],
            'business_name' => ['required', 'string', 'max:255'],
            'sunat_mode' => ['nullable', 'in:beta,production'],
            'api_client_name' => ['nullable', 'string', 'max:120'],
            'ruc_sol' => ['nullable', 'string', 'size:11', 'regex:/^[0-9]{11}$/'],
            'usuario_sol' => ['nullable', 'string', 'max:120'],
            'clave_sol' => ['nullable', 'string', 'max:255'],
            'certificado_password' => ['nullable', 'string', 'max:255'],
            'certificado_url' => ['nullable', 'string', 'max:500'],
            'logo_pdf_url' => ['nullable', 'string', 'max:500'],
            'serie_factura' => ['nullable', 'string', 'max:10'],
            'serie_boleta' => ['nullable', 'string', 'max:10'],
            'serie_nc' => ['nullable', 'string', 'max:10'],
            'serie_nd' => ['nullable', 'string', 'max:10'],
            'serie_guia' => ['nullable', 'string', 'max:10'],
            'igv' => ['nullable', 'numeric'],
            'moneda' => ['nullable', 'string', 'size:3'],
            'logo_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,svg', 'max:2048'],
            'certificado_file' => ['nullable', 'file', 'mimes:pem,pfx,p12', 'max:5120'],
        ]);

        $data['logo_file'] = $request->file('logo_file');
        $data['certificado_file'] = $request->file('certificado_file');
        $this->assertLogoProvided($data['logo_pdf_url'] ?? null, $data['logo_file'] ?? null);
        $this->assertProductionSolCredentials(
            $data['sunat_mode'] ?? null,
            $data['ruc_sol'] ?? null,
            $data['usuario_sol'] ?? null,
            $data['clave_sol'] ?? null,
        );
        $this->assertProductionCertificateProvided(
            $data['sunat_mode'] ?? null,
            $data['certificado_file'] ?? null,
            $data['certificado_url'] ?? null,
        );

        $result = $this->registerTenantUseCase->execute($data);
        $status = ($result['already_exists'] ?? false) ? 200 : 201;

        return ApiResponse::success($result, $status);
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success($this->showTenantUseCase->execute($id));
    }

    public function sucursales(int $id): JsonResponse
    {
        return ApiResponse::success($this->listTenantSucursalesUseCase->execute($id));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'business_name' => ['sometimes', 'string', 'max:255'],
            'sunat_mode' => ['sometimes', 'in:beta,production'],
            'is_active' => ['sometimes', 'boolean'],
            'ruc_sol' => ['sometimes', 'string', 'size:11', 'regex:/^[0-9]{11}$/'],
            'usuario_sol' => ['sometimes', 'string', 'max:120'],
            'clave_sol' => ['sometimes', 'string', 'max:255'],
            'certificado_password' => ['sometimes', 'string', 'max:255'],
            'certificado_url' => ['sometimes', 'string', 'max:500'],
            'logo_pdf_url' => ['sometimes', 'string', 'max:500'],
            'serie_factura' => ['sometimes', 'string', 'max:10'],
            'serie_boleta' => ['sometimes', 'string', 'max:10'],
            'serie_nc' => ['sometimes', 'string', 'max:10'],
            'serie_nd' => ['sometimes', 'string', 'max:10'],
            'serie_guia' => ['sometimes', 'string', 'max:10'],
            'igv' => ['sometimes', 'numeric'],
            'moneda' => ['sometimes', 'string', 'size:3'],
            'logo_file' => ['sometimes', 'file', 'mimes:jpg,jpeg,png,webp,svg', 'max:2048'],
            'certificado_file' => ['sometimes', 'file', 'mimes:pem,pfx,p12', 'max:5120'],
        ]);

        $data['logo_file'] = $request->file('logo_file');
        $data['certificado_file'] = $request->file('certificado_file');

        return ApiResponse::success($this->updateTenantUseCase->execute($id, $data));
    }

    public function destroy(int $id): JsonResponse
    {
        return ApiResponse::success($this->deleteTenantUseCase->execute($id));
    }

    private function assertProductionSolCredentials(?string $sunatMode, mixed $rucSol, mixed $usuarioSol, mixed $claveSol): void
    {
        if ($sunatMode !== 'production') {
            return;
        }

        $missing = [];
        if (trim((string) $rucSol) === '') {
            $missing['ruc_sol'] = ['En modo production debes registrar el RUC SOL real.'];
        }
        if (trim((string) $usuarioSol) === '') {
            $missing['usuario_sol'] = ['En modo production debes registrar el usuario SOL real.'];
        }
        if (trim((string) $claveSol) === '') {
            $missing['clave_sol'] = ['En modo production debes registrar la clave SOL real.'];
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }

    private function assertProductionCertificateProvided(?string $sunatMode, mixed $certificateFile, mixed $certificateUrl): void
    {
        if ($sunatMode !== 'production') {
            return;
        }

        if ($certificateFile instanceof UploadedFile || trim((string) $certificateUrl) !== '') {
            return;
        }

        throw ValidationException::withMessages([
            'certificado_file' => [
                'En modo production debes adjuntar el archivo de firma digital (.pem, .pfx o .p12) o registrar certificado_url.',
            ],
        ]);
    }

    private function assertLogoProvided(?string $logoPdfUrl, mixed $logoFile): void
    {
        $hasLogoUrl = trim((string) $logoPdfUrl) !== '';
        $hasLogoFile = $logoFile instanceof UploadedFile;

        if ($hasLogoUrl || $hasLogoFile) {
            return;
        }

        throw ValidationException::withMessages([
            'logo_file' => [
                'Debes registrar el logo de la empresa (archivo o URL) para crear el tenant.',
            ],
        ]);
    }
}
