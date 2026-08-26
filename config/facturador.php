<?php

return [
    'name' => env('FACTURADOR_NAME', 'AZURION FACTURADOR'),
    'auth_disabled' => (bool) env('AUTH_DISABLED', false),
    'fiscal_timezone' => env('FACTURADOR_FISCAL_TIMEZONE', 'America/Lima'),
    'sunat' => [
        'timeout' => (int) env('SUNAT_TIMEOUT', 30),
        'queues' => [
            'beta' => env('SUNAT_BETA_QUEUE', env('DB_QUEUE', 'default')),
            'production' => env('SUNAT_PRODUCTION_QUEUE', env('DB_QUEUE', 'default')),
        ],
    ],
    'storage' => [
        'disk' => env('TENANT_STORAGE_DISK', 'tenants'),
    ],
    'docs' => [
        // Fuera de local/testing la documentacion OpenAPI responde 404 salvo
        // que se abra de forma explicita para un entorno concreto.
        'public' => (bool) env('L5_SWAGGER_PUBLIC', false),
    ],
    'artifacts' => [
        // Los enlaces persistidos son solo referencias temporales. El historial
        // solicita una firma nueva al facturador cada vez que se consulta.
        'signed_url_ttl_minutes' => (int) env('FACTURADOR_ARTIFACT_URL_TTL_MINUTES', 30),
    ],
    'ubigeos' => [
        // Ruta opcional a CSV de equivalencias INEI/RENIEC/SUNAT.
        'equivalences_csv_path' => env('UBIGEO_EQUIVALENCES_CSV_PATH'),
    ],
    'security' => [
        'encrypt_sol_key' => true,
        'hmac' => [
            'required_with_api_key' => (bool) env('HMAC_REQUIRED_WITH_API_KEY', true),
            'header_signature' => env('HMAC_SIGNATURE_HEADER', 'X-Signature'),
            'header_timestamp' => env('HMAC_TIMESTAMP_HEADER', 'X-Timestamp'),
            'header_nonce' => env('HMAC_NONCE_HEADER', 'X-Nonce'),
            'header_version' => env('HMAC_SIGNATURE_VERSION_HEADER', 'X-Signature-Version'),
            'timestamp_tolerance_seconds' => (int) env('HMAC_TOLERANCE_SECONDS', 300),
            'nonce_ttl_seconds' => (int) env('HMAC_NONCE_TTL_SECONDS', 600),
        ],
    ],
    'integrations' => [
        'azurion' => [
            'inbound_client_id' => env('AZURION_INTEGRATION_CLIENT_ID', 'azurion-core'),
            'inbound_client_secret' => env('AZURION_INTEGRATION_SECRET', ''),
            'inbound_previous_secret' => env('AZURION_INTEGRATION_PREVIOUS_SECRET', ''),
            'header_client_id' => env('AZURION_INTEGRATION_CLIENT_ID_HEADER', 'X-Client-Id'),
            'allow_legacy_api_key' => (bool) env('AZURION_INTEGRATION_ALLOW_LEGACY_API_KEY', false),
            // Compatibilidad temporal con el protocolo anterior.
            'inbound_api_key' => env('AZURION_INTEGRATION_API_KEY', ''),
            'enabled' => (bool) env('AZURION_CALLBACK_ENABLED', true),
            'callback_url' => env('AZURION_CALLBACK_URL', 'http://127.0.0.1:8080/api/v1/facturador/callback/ventas'),
            'callback_urls' => [
                'ventas' => env('AZURION_CALLBACK_URL_VENTAS', env('AZURION_CALLBACK_URL', 'http://127.0.0.1:8080/api/v1/facturador/callback/ventas')),
                'guias' => env('AZURION_CALLBACK_URL_GUIAS', env('AZURION_CALLBACK_URL', 'http://127.0.0.1:8080/api/v1/facturador/callback/ventas')),
                'notas_credito' => env('AZURION_CALLBACK_URL_NOTAS_CREDITO', env('AZURION_CALLBACK_URL', 'http://127.0.0.1:8080/api/v1/facturador/callback/ventas')),
                'notas_debito' => env('AZURION_CALLBACK_URL_NOTAS_DEBITO', env('AZURION_CALLBACK_URL', 'http://127.0.0.1:8080/api/v1/facturador/callback/ventas')),
                'documentos' => env('AZURION_CALLBACK_URL_DOCUMENTOS', env('AZURION_CALLBACK_URL', 'http://127.0.0.1:8080/api/v1/facturador/callback/ventas')),
            ],
            'api_key' => env('AZURION_CALLBACK_API_KEY', ''),
            'shared_secret' => env('AZURION_CALLBACK_SECRET', ''),
            'header_api_key' => env('AZURION_CALLBACK_HEADER_API_KEY', 'X-API-Key'),
            'header_signature' => env('AZURION_CALLBACK_HEADER_SIGNATURE', 'X-Signature'),
            'header_timestamp' => env('AZURION_CALLBACK_HEADER_TIMESTAMP', 'X-Timestamp'),
            'header_nonce' => env('AZURION_CALLBACK_HEADER_NONCE', 'X-Nonce'),
            'connect_timeout_seconds' => (int) env('AZURION_CALLBACK_CONNECT_TIMEOUT_SECONDS', 3),
            'timeout_seconds' => (int) env('AZURION_CALLBACK_TIMEOUT_SECONDS', 8),
        ],
    ],
];
