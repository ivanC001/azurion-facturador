# Azurion Facturador - Clean Architecture en Laravel

Estructura activa del proyecto:

- `app/Http`: capa de presentacion (controllers, middleware).
- `app/Application`: casos de uso (orquestacion del negocio).
- `app/Domain`: reglas centrales, contratos y eventos de dominio.
- `app/Infrastructure`: adaptadores tecnicos (Eloquent repos, SUNAT, PDF, tenant infra).
- `app/Models`: modelos Eloquent (detalle de persistencia de Laravel).
- `app/Jobs`: procesamiento async con colas.
- `app/Support`: utilidades transversales (`ApiResponse`, `TenantContext`).

## Reglas de dependencia

1. `Http` depende de `Application`.
2. `Application` depende de `Domain` (contratos/puertos).
3. `Infrastructure` implementa contratos de `Domain`.
4. `Domain` no depende de Laravel HTTP ni de controladores.

## Flujo principal de facturacion

1. `DocumentoController` recibe request y valida.
2. `CreateDocumentoUseCase` persiste y encola envio.
3. `ProcessSunatDocumentJob` procesa async.
4. `GreenterSunatSender` genera XML, firma, envia SUNAT.
5. Se guardan XML/PDF/CDR por tenant.
6. Se actualiza estado y se publica evento de dominio.

## Beneficios para mantenimiento

- Controladores delgados.
- Casos de uso testeables.
- Integraciones externas aisladas en `Infrastructure`.
- Contratos explicitos para evolucionar sin romper capa de negocio.
