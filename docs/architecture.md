# Arquitectura Backend - AZURION FACTURADOR

## Estilo

- Clean Architecture
- DDD por modulo
- Modular Monolith
- Event Driven interno
- MultiTenant PostgreSQL por schema

## Modulos

Cada modulo se organiza en:

- `Domain`
- `Application`
- `Infrastructure`
- `Presentation`

Modulos activos:

- `Auth`
- `Tenants`
- `Documentos`
- `Sunat`
- `Auditoria`

Se dejaron estructurados los modulos de expansion:

- `Configuracion`, `Detalles`, `Tributos`, `XML`, `PDF`, `CDR`, `QR`, `Guias`, `Resumenes`, `Bajas`, `Detracciones`, `Certificados`, `Storage`

## MultiTenant

1. `auth.api` valida JWT o API Key.
2. `resolve.tenant` identifica tenant por `tenant_id` o `X-Tenant-RUC`.
3. `tenant.search_path` aplica `SET search_path TO empresa_x, public`.
4. Toda consulta operativa queda aislada por schema.

## Flujo de documento

1. `POST /api/documentos`
2. `CreateDocumentoUseCase`
3. `DocumentoRepository` persiste en tenant
4. Dispatch `ProcessSunatDocumentJob` (cola `sunat`)
5. `GreenterSunatSender` (stub preparado)
6. Persistencia de estado/ticket/hash
7. Almacen de `xml/pdf/cdr` en `storage/app/tenants/{ruc}`
8. Eventos de dominio + auditoria

## Componentes de plataforma

- Redis para queue/cache/horizon
- Horizon para observabilidad de colas
- OpenAPI con L5 Swagger
- Docker Compose para entorno reproducible

## Extensiones inmediatas

- Reemplazar `GreenterSunatSender` por envio SUNAT real con firma digital y CDR real.
- Implementar consecutivos/series transaccionales por tipo de comprobante.
- Separar modulo `Sunat` en microservicio cuando crezca volumen.
