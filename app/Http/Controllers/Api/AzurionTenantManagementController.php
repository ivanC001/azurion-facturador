<?php

namespace App\Http\Controllers\Api;

use App\Application\Tenants\UseCases\ListTenantsUseCase;
use App\Application\Tenants\UseCases\RegisterTenantUseCase;
use App\Application\Tenants\UseCases\ShowTenantUseCase;
use App\Application\Tenants\UseCases\UpdateTenantUseCase;
use App\Models\Tenant;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AzurionTenantManagementController
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    public function __construct(
        private readonly ListTenantsUseCase $listTenantsUseCase,
        private readonly RegisterTenantUseCase $registerTenantUseCase,
        private readonly ShowTenantUseCase $showTenantUseCase,
        private readonly UpdateTenantUseCase $updateTenantUseCase,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->assertPlatformScope($request);

        return ApiResponse::success($this->listTenantsUseCase->execute());
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertPlatformScope($request);
        $data = $this->validatedPayload($request, true);

        try {
            $result = $this->registerTenantUseCase->execute($data);
        } finally {
            $this->cleanupTemporaryFiles();
        }

        return ApiResponse::success($result, ($result['already_exists'] ?? false) ? 200 : 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($id);
        $this->assertTenantScope($request, $tenant);

        return ApiResponse::success($this->showTenantUseCase->execute($tenant->id));
    }

    public function showExternal(Request $request, string $externalTenantId): JsonResponse
    {
        $tenant = Tenant::query()->where('external_tenant_id', $externalTenantId)->firstOrFail();
        $this->assertTenantScope($request, $tenant);

        return ApiResponse::success($this->showTenantUseCase->execute($tenant->id));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($id);

        return $this->updateTenant($request, $tenant);
    }

    public function updateExternal(Request $request, string $externalTenantId): JsonResponse
    {
        $tenant = Tenant::query()->where('external_tenant_id', $externalTenantId)->firstOrFail();

        return $this->updateTenant($request, $tenant);
    }

    private function updateTenant(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertTenantScope($request, $tenant);
        $data = $this->validatedPayload($request, false);

        try {
            return ApiResponse::success($this->updateTenantUseCase->execute($tenant->id, $data));
        } finally {
            $this->cleanupTemporaryFiles();
        }
    }

    /** @return array<string, mixed> */
    private function validatedPayload(Request $request, bool $creating): array
    {
        $presence = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'ruc' => [$presence, 'string', 'size:11', 'regex:/^[0-9]{11}$/'],
            'business_name' => [$presence, 'string', 'max:255'],
            'external_tenant_id' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]{2,80}$/'],
            'country_code' => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'tax_id' => ['nullable', 'string', 'max:40'],
            'sunat_mode' => ['nullable', 'in:disabled,beta,production'],
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
            'logo_file_base64' => ['nullable', 'string', 'max:2800000'],
            'logo_file_name' => ['nullable', 'string', 'max:180'],
            'certificado_file_base64' => ['nullable', 'string', 'max:7000000'],
            'certificado_file_name' => ['nullable', 'string', 'max:180'],
        ]);

        if (! empty($data['logo_file_base64'])) {
            $data['logo_file'] = $this->decodeUpload(
                (string) $data['logo_file_base64'],
                (string) ($data['logo_file_name'] ?? 'logo.bin'),
                ['jpg', 'jpeg', 'png', 'webp'],
                2 * 1024 * 1024,
                'logo_file',
            );
        }
        if (! empty($data['certificado_file_base64'])) {
            $data['certificado_file'] = $this->decodeUpload(
                (string) $data['certificado_file_base64'],
                (string) ($data['certificado_file_name'] ?? 'certificado.bin'),
                ['pem', 'pfx', 'p12'],
                5 * 1024 * 1024,
                'certificado_file',
            );
        }

        unset(
            $data['logo_file_base64'],
            $data['logo_file_name'],
            $data['certificado_file_base64'],
            $data['certificado_file_name'],
        );

        return $data;
    }

    /** @param list<string> $allowedExtensions */
    private function decodeUpload(
        string $encoded,
        string $originalName,
        array $allowedExtensions,
        int $maxBytes,
        string $field,
    ): UploadedFile {
        $bytes = base64_decode($encoded, true);
        $extension = strtolower(pathinfo(basename($originalName), PATHINFO_EXTENSION));
        if ($bytes === false || strlen($bytes) > $maxBytes || ! in_array($extension, $allowedExtensions, true)) {
            throw ValidationException::withMessages([$field => ['El archivo enviado no es valido.']]);
        }

        $path = tempnam(sys_get_temp_dir(), 'azurion-upload-');
        if ($path === false || file_put_contents($path, $bytes) === false) {
            throw ValidationException::withMessages([$field => ['No se pudo procesar el archivo enviado.']]);
        }
        $this->temporaryFiles[] = $path;

        return new UploadedFile(
            $path,
            Str::limit(basename($originalName), 180, ''),
            mime_content_type($path) ?: 'application/octet-stream',
            null,
            true,
        );
    }

    private function integrationTenantId(Request $request): string
    {
        return trim((string) $request->header('X-Azurion-Tenant-ID', ''));
    }

    private function assertPlatformScope(Request $request): void
    {
        abort_unless($this->integrationTenantId($request) === 'platform', 403, 'Platform integration scope required.');
    }

    private function assertTenantScope(Request $request, Tenant $tenant): void
    {
        $scope = $this->integrationTenantId($request);
        abort_unless(
            $scope === 'platform' || hash_equals((string) $tenant->external_tenant_id, $scope),
            403,
            'Tenant integration scope mismatch.',
        );
    }

    private function cleanupTemporaryFiles(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->temporaryFiles = [];
    }
}
