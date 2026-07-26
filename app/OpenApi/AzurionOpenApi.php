<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(version: '1.0.0', title: 'AZURION FACTURADOR API', description: 'Motor SaaS multi-tenant de facturacion electronica SUNAT')]
#[OA\Server(url: '/api', description: 'API base')]
#[OA\SecurityScheme(securityScheme: 'bearerAuth', type: 'http', bearerFormat: 'JWT', scheme: 'bearer')]
#[OA\SecurityScheme(securityScheme: 'apiKeyAuth', type: 'apiKey', in: 'header', name: 'X-API-Key')]
#[OA\PathItem(path: '/documentos')]
final class AzurionOpenApi
{
    #[OA\Post(
        path: '/documentos',
        summary: 'Registrar factura/boleta para procesamiento async SUNAT',
        security: [['bearerAuth' => []], ['apiKeyAuth' => []]],
        tags: ['Documentos'],
        responses: [
            new OA\Response(response: 202, description: 'Documento encolado'),
            new OA\Response(response: 422, description: 'Validacion fallida'),
        ]
    )]
    public function postDocumentos(): void
    {
    }

    #[OA\Post(
        path: '/sunat/enviar',
        summary: 'Reencolar envio SUNAT',
        security: [['bearerAuth' => []], ['apiKeyAuth' => []]],
        tags: ['Sunat'],
        responses: [
            new OA\Response(response: 202, description: 'Solicitud en cola'),
        ]
    )]
    public function postSunatEnviar(): void
    {
    }

    #[OA\Get(
        path: '/sunat/estado',
        summary: 'Consultar estado SUNAT por documento_id',
        security: [['bearerAuth' => []], ['apiKeyAuth' => []]],
        tags: ['Sunat'],
        parameters: [
            new OA\Parameter(name: 'documento_id', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Estado actual'),
        ]
    )]
    public function getSunatEstado(): void
    {
    }
}
