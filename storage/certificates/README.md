# Certificados del servidor

Este directorio contiene **unicamente** el certificado de pruebas que SUNAT
publica de forma abierta para el entorno beta (`ejemplo123456789`). Esta
versionado a proposito: sin el, el modo `beta` no puede firmar y los tests de
entorno fallan.

No es material sensible y no debe tratarse como tal.

## Que nunca va aqui

Los certificados reales de los contribuyentes **no** se guardan en este
directorio ni en el repositorio. Se suben por la API (`certificado_file`) y
quedan en el disco privado del tenant, fuera del arbol de git:

```
storage/app/tenants/{ruc}/certificados/
```

El facturador rechaza el modo `production` si detecta que el certificado
configurado es este de prueba, tanto por nombre como comparando su hash
(ver `App\Support\Sunat\SunatTestIdentity`).
