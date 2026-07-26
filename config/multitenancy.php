<?php

return [
    'tenant_header' => env('TENANT_HEADER', 'X-Tenant-RUC'),
    'default_schema' => env('DB_SCHEMA', 'public'),
    'schema_prefix' => env('TENANT_SCHEMA_PREFIX', 'empresa_'),
];
