# API y convenciones

## Base
`/api/v1`

## Respuestas
Formato consistente:
```
{
  "data": {},
  "meta": {},
  "message": null
}
```
Errores con código estable y mensaje en español.

## Recursos ejemplo
- /organizations
- /organizations/{organization}/members
- /brands
- /brands/{brand}/social-connections
- /content
- /content/{content}/variants
- /campaigns
- /calendar
- /analytics
- /billing/subscription

## Reglas
- UUID/ULID público recomendado.
- Pagination obligatoria en listados grandes.
- filtros y sorting allowlisted.
- rate limit por tipo de endpoint.
- OpenAPI generado/mantenido.
- versionado desde el inicio.
- Request IDs/correlation IDs.
