# AZURION FACTURADOR

Motor SaaS de facturacion electronica SUNAT en Laravel 12 con PostgreSQL multi-tenant por schema.

## Stack

- Laravel 12 / PHP 8.3+
- PostgreSQL 16
- Redis + Queues + Horizon
- Greenter (base de integracion)
- JWT + API Keys
- Docker
- OpenAPI (L5 Swagger)

## Arquitectura

- Modular Monolith + DDD + Clean Architecture
- Event Driven interno
- MultiTenant por `search_path` en PostgreSQL
- Listo para evolucion a microservicios

Estructura principal:

- `app/Modules/*/{Domain,Application,Infrastructure,Presentation}`
- `app/MultiTenancy/*`
- `app/Security/*`
- `app/Shared/*`

## MultiTenant por Schema

Schemas esperados:

- `public`
- `empresa_20601234567`
- `empresa_20111111111`

`public` contiene:

- `tenants`
- `catalogos_sunat`
- `tipos_documento`
- `tipos_tributos`
- `monedas`
- `ubigeos`
- `paises`
- `unidades_medida`
- `configuraciones_globales`
- `api_clients`

Cada tenant contiene tablas operativas de facturacion, almacenamiento, SUNAT y auditoria (provisionadas por `TenantSchemaManager`).

## Flujo async de facturacion

1. API recibe documento
2. Valida payload
3. Persiste en `documentos`
4. Encola `ProcessSunatDocumentJob`
5. Worker procesa: XML, hash, SUNAT, CDR, PDF
6. Persiste resultado y auditoria

## Seguridad

- Middleware `auth.api`: JWT o API Key
- Middleware `resolve.tenant`
- Middleware `tenant.search_path`
- Rate limiting por cliente/IP
- Para integracion servidor-servidor se exige firma HMAC SHA-256 con identidad
  de cliente, timestamp y nonce.

### Client ID + HMAC v2 (server-to-server)

Headers requeridos para Azurion:

- `X-Client-Id`
- `X-Signature-Version: v2`
- `X-Timestamp` (unix seconds o ms)
- `X-Nonce` (unico por request)
- `X-Signature` (base64 o hex)
- `X-Azurion-Tenant-ID`
- `X-Tenant-RUC` cuando exista identificador fiscal

String canonica a firmar:

```text
v2\n
{METHOD}\n
{REQUEST_URI}\n
{X-Timestamp}\n
{X-Nonce}\n
{X-Azurion-Tenant-ID}\n
{X-Tenant-RUC}\n
{SHA256_BODY_HEX}
```

Firma:

```text
HMAC_SHA256(canonical_string, client_secret)
```

El secreto nunca se envia. El facturador selecciona el secreto usando el
`X-Client-Id`. `AZURION_INTEGRATION_PREVIOUS_SECRET` permite una rotacion sin
caida: primero se publica el secreto nuevo en el facturador, luego en Azurion y
finalmente se elimina el anterior.

Las API keys antiguas de clientes tenant continúan usando HMAC v1 durante la
migracion. La integración global heredada solo se habilita explícitamente con
`AZURION_INTEGRATION_ALLOW_LEGACY_API_KEY=true`.

Respuesta esperada en caso de identidad o firma inválida: `401`.

### Restriccion para modo production

- En `sunat_mode=production` el endpoint de tenants exige `certificado_file`.
- Formatos permitidos por backend: `pem`, `pfx`, `p12`.
- Sin archivo de firma digital, la API responde `422`.

### Entornos SUNAT y colas

El destino se decide por tenant mediante `public.tenants.sunat_mode`; no existe un modo global para todas las empresas.

- `beta`: usa automaticamente `20000000001 / MODDATOS`, la clave y el certificado de prueba incluidos en el servidor. Facturas y boletas se envian a `SUNAT_BETA_URL` mediante la cola `SUNAT_BETA_QUEUE`.
- `production`: usa exclusivamente las credenciales SOL y el certificado real guardados para el tenant. Facturas y boletas se envian a `SUNAT_PROD_URL` mediante la cola `SUNAT_PRODUCTION_QUEUE`.
- Las guias usan sus endpoints separados `SUNAT_GUIA_BETA_URL` y `SUNAT_GUIA_PROD_URL`.

Valores recomendados para las colas:

```dotenv
SUNAT_BETA_QUEUE=sunat-beta
SUNAT_PRODUCTION_QUEUE=sunat-production
```

Un worker puede atender ambas colas con:

```bash
php artisan queue:work --queue=sunat-production,sunat-beta,sunat,default
```

Cada envio registra entorno, endpoint, cola, estado y codigo SUNAT en `storage/logs/sunat-AAAA-MM-DD.log`, sin escribir claves ni certificados.

### Escalado horizontal y archivos

`TENANT_STORAGE_DISK` debe apuntar a almacenamiento compartido y persistente si
se ejecuta mas de una replica (volumen RWX u object storage compatible). No uses
un directorio local distinto por contenedor: el worker que genera PDF/XML/CDR y
la replica que atiende la descarga pueden no ser la misma. Redis tambien debe
ser compartido porque coordina colas, nonces y bloqueos de generacion de PDF.

El `retry_after` de Redis debe ser mayor que el timeout del job SUNAT. La
configuracion incluida usa 180 segundos frente a 120 segundos de ejecucion para
evitar que un segundo worker tome el mismo documento mientras el primero sigue
procesandolo.

### Ubigeo, IGV y fecha de emision

- Se normaliza ubigeo usando el catalogo `ubigeos` y/o CSV de equivalencias (`UBIGEO_EQUIVALENCES_CSV_PATH`).
- Para ubigeos exonerados (config `TAX_EXEMPT_DEPARTMENT_CODES` / `TAX_EXEMPT_UBIGEO_CODES`) se fuerza IGV=0 en facturas y boletas.
- La factura (`tipo 01`) solo se acepta con fecha de emision entre hoy y hasta 2 dias anteriores (zona horaria `FACTURADOR_FISCAL_TIMEZONE`, por defecto `America/Lima`).

## Endpoints

- `POST /api/documentos`
- `POST /api/notas-credito`
- `POST /api/notas-debito`
- `POST /api/guias`
- `POST /api/resumenes`
- `GET /api/documentos/{id}`
- `GET /api/documentos/{id}/pdf`
- `GET /api/documentos/{id}/xml`
- `GET /api/documentos/{id}/cdr`
- `POST /api/sunat/enviar`
- `GET /api/sunat/estado?documento_id={id}`
- `POST /api/tenants`

## Local con Docker

```bash
docker compose up -d --build
docker compose exec app cp .env.example .env
docker compose exec app php artisan key:generate
docker compose exec app php artisan jwt:secret
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed
```

## Comandos utiles

```bash
php artisan tenant:provision 20601234567
php artisan ubigeos:import "C:\ruta\equivalencia-ubigeos.csv"
php artisan queue:work redis --queue=sunat-production,sunat-beta,sunat,default
php artisan queue:work --queue=sunat-production,sunat-beta,default
php artisan horizon
php artisan l5-swagger:generate
```

## Notas de integracion SUNAT

La clase `GreenterSunatSender` ya define el puerto de integracion para reemplazar el stub por envio real con:

- UBL 2.1
- Firma digital
- envio SUNAT
- lectura CDR
- codigos de respuesta

