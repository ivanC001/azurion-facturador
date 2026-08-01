<?php

namespace App\Http\Controllers\Api;

use App\Application\Tenants\UseCases\DeleteTenantUseCase;
use App\Application\Tenants\UseCases\ListTenantSucursalesUseCase;
use App\Application\Tenants\UseCases\ListTenantsUseCase;
use App\Application\Tenants\UseCases\RegisterTenantUseCase;
use App\Application\Tenants\UseCases\ShowTenantUseCase;
use App\Application\Tenants\UseCases\UpdateTenantUseCase;
use App\Models\Tenant;
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

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->listTenantsUseCase->execute(
            $this->isPlatformAdmin($request) ? null : $this->authenticatedTenantId($request),
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertPlatformAdmin($request);

        $data = $request->validate([
            'ruc' => ['required', 'string', 'size:11', 'regex:/^[0-9]{11}$/'],
            'business_name' => ['required', 'string', 'max:255'],
            'sunat_mode' => ['nullable', 'in:disabled,beta,production'],
            'external_tenant_id' => ['nullable', 'string', 'max:80'],
            'country_code' => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'tax_id' => ['nullable', 'string', 'max:40'],
            'api_client_name' => ['nullable', 'string', 'max:120'],
            'ruc_sol' => ['nullable', 'string', 'size:11', 'regex:/^[0-9]{11}$/'],
            'usuario_sol' => ['nullable', 'string', 'max:120'],
            'clave_sol' => ['nullable', 'string', 'max:255'],
            'certificado_password' => ['nullable', 'string', 'max:255'],
            'serie_factura' => ['nullable', 'string', 'max:10'],
            'serie_boleta' => ['nullable', 'string', 'max:10'],
            'serie_nc' => ['nullable', 'string', 'max:10'],
            'serie_nd' => ['nullable', 'string', 'max:10'],
            'serie_guia' => ['nullable', 'string', 'max:10'],
            'igv' => ['nullable', 'numeric'],
            'moneda' => ['nullable', 'string', 'size:3'],
            'logo_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'certificado_file' => ['nullable', 'file', 'mimes:pem,pfx,p12', 'max:5120'],
        ]);

        $data['logo_file'] = $request->file('logo_file');
        $data['certificado_file'] = $request->file('certificado_file');
        $this->assertPeruForElectronicMode($data['sunat_mode'] ?? null, $data['country_code'] ?? 'PE');
        $this->assertProductionSolCredentials(
            $data['sunat_mode'] ?? null,
            $data['ruc_sol'] ?? null,
            $data['usuario_sol'] ?? null,
            $data['clave_sol'] ?? null,
        );
        $this->assertProductionCertificateProvided(
            $data['sunat_mode'] ?? null,
            $data['certificado_file'] ?? null,
        );

        $result = $this->registerTenantUseCase->execute($data);
        $status = ($result['already_exists'] ?? false) ? 200 : 201;

        return ApiResponse::success($result, $status);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->assertTenantAccess($request, $id);

        return ApiResponse::success($this->showTenantUseCase->execute($id));
    }

    public function sucursales(Request $request, int $id): JsonResponse
    {
        $this->assertTenantAccess($request, $id);

        return ApiResponse::success($this->listTenantSucursalesUseCase->execute($id));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->assertTenantAccess($request, $id);

        $data = $request->validate([
            'sunat_mode' => ['sometimes', 'in:disabled,beta,production'],
            'ruc_sol' => ['sometimes', 'string', 'size:11', 'regex:/^[0-9]{11}$/'],
            'usuario_sol' => ['sometimes', 'string', 'max:120'],
            'clave_sol' => ['sometimes', 'string', 'max:255'],
            'certificado_password' => ['sometimes', 'string', 'max:255'],
            'serie_factura' => ['sometimes', 'string', 'max:10'],
            'serie_boleta' => ['sometimes', 'string', 'max:10'],
            'serie_nc' => ['sometimes', 'string', 'max:10'],
            'serie_nd' => ['sometimes', 'string', 'max:10'],
            'serie_guia' => ['sometimes', 'string', 'max:10'],
            'igv' => ['sometimes', 'numeric'],
            'moneda' => ['sometimes', 'string', 'size:3'],
            'logo_file' => ['sometimes', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'certificado_file' => ['sometimes', 'file', 'mimes:pem,pfx,p12', 'max:5120'],
        ]);

        $data['logo_file'] = $request->file('logo_file');
        $data['certificado_file'] = $request->file('certificado_file');
        if (array_key_exists('sunat_mode', $data)) {
            $this->assertPeruForElectronicMode($data['sunat_mode'], $data['country_code'] ?? null);
        }

        return ApiResponse::success($this->updateTenantUseCase->execute($id, $data));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->assertPlatformAdmin($request);

        return ApiResponse::success($this->deleteTenantUseCase->execute($id));
    }

    private function assertTenantAccess(Request $request, int $tenantId): void
    {
        if ($this->isPlatformAdmin($request)) {
            return;
        }

        if ($this->authenticatedTenantId($request) !== $tenantId) {
            abort(403, 'No puedes acceder a la configuracion de otro tenant.');
        }
    }

    private function assertPlatformAdmin(Request $request): void
    {
        if (! $this->isPlatformAdmin($request)) {
            abort(403, 'Se requieren permisos de administrador del facturador.');
        }
    }

    private function isPlatformAdmin(Request $request): bool
    {
        return (bool) config('facturador.auth_disabled', false)
            || (bool) $request->attributes->get('facturador_platform_admin', false);
    }

    private function authenticatedTenantId(Request $request): int
    {
        $tenantClaim = trim((string) $request->attributes->get('tenant_id', ''));
        $tenantId = filter_var(
            $tenantClaim,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($tenantId !== false) {
            return $tenantId;
        }

        $resolvedId = Tenant::query()
            ->where('external_tenant_id', $tenantClaim)
            ->value('id');
        if ($resolvedId === null) {
            abort(403, 'La sesion no contiene un tenant valido.');
        }

        return (int) $resolvedId;
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

    private function assertProductionCertificateProvided(?string $sunatMode, mixed $certificateFile): void
    {
        if ($sunatMode !== 'production') {
            return;
        }

        if ($certificateFile instanceof UploadedFile) {
            return;
        }

        throw ValidationException::withMessages([
            'certificado_file' => [
                'En modo production debes adjuntar el archivo de firma digital (.pem, .pfx o .p12).',
            ],
        ]);
    }

    private function assertPeruForElectronicMode(?string $sunatMode, ?string $countryCode): void
    {
        if ($sunatMode === null || $sunatMode === 'disabled' || $countryCode === null) {
            return;
        }
        if (strtoupper(trim((string) $countryCode)) !== 'PE') {
            throw ValidationException::withMessages([
                'country_code' => ['La facturacion electronica SUNAT solo esta disponible para empresas de Peru.'],
            ]);
        }
    }

}
