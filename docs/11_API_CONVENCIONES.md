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

---

## API pública + MCP — Implementación (Fase 11)

### Autenticación por API key
Base pública: `/api/public/v1` (separada de la API de sesión `/api/v1`). Se
autentica con `Authorization: Bearer <api-key>` (o `X-Api-Key`). La key resuelve
la Organization y fija el contexto de tenant (aislamiento por `OrganizationScope`).

- `api_keys`: sólo se guarda el **hash SHA-256** del secreto; el valor completo
  (`l7_<prefix>_<secreto>`) se muestra **una única vez** al crearla.
- Requisitos: entitlement `feature.api` (403 si el plan no lo incluye) y, para
  gestionarlas, el permiso `api.manage` (owner/admin).
- Gestión desde el panel (sesión Sanctum): `GET|POST /api/v1/api-keys`,
  `DELETE /api/v1/api-keys/{apiKey}`.

### Scopes y rate limit
Cada key concede scopes (`brands:read`, `content:read`, `content:write`,
`analytics:read`) y cada endpoint exige el suyo (middleware `scope:*`, 403
`insufficient_scope`). Rate limit por key: `throttle:public-api` (120/min por key),
429 con `too_many_requests`.

### Endpoints públicos
- `GET /me` — introspección (organización + scopes de la key).
- `GET /brands` — `brands:read`.
- `GET /brands/{brand}/content` — `content:read`.
- `POST /brands/{brand}/content` — `content:write` (crea borrador).
- `GET /brands/{brand}/analytics` — `analytics:read` (reutiliza Analytics;
  `from`/`to` opcionales, máximo 365 días por consulta, como en la app).

Los listados aceptan `per_page` (1–100, 30 por defecto). Los borradores creados por
API o MCP pasan por el mismo `ContentService` que la app: quedan en la auditoría de la
organización con `via` (`api` | `mcp`), `api_key_id` y `api_key_name` (nunca el secreto).

### Servidor MCP
`POST /api/public/v1/mcp` — JSON-RPC 2.0 sobre la misma API y autenticación por
key. Métodos: `initialize`, `ping`, `tools/list` (filtra herramientas por los
scopes de la key), `tools/call`. Herramientas: `list_brands`, `list_content`,
`create_content`, `get_analytics` (cada una exige su scope). Los errores de una
herramienta vuelven como `isError` con un mensaje para el usuario (validación, marca
inexistente); los fallos internos se registran y nunca exponen detalles.

### Webhooks salientes
Disponibles a través de la acción `webhook` de **Automations** (Fase 10), que hace
POST firmable a una URL cuando ocurre un disparador. Un módulo de suscripciones de
webhooks con firma HMAC queda como evolución futura.

### Frontend
Vista **API y accesos** (`/app/api-keys`): crear key (muestra el secreto una vez con
copiar), lista con scopes y último uso, revocar; y referencia de base URL + MCP.
