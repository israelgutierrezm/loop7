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
- `GET /brands/{brand}/analytics/best-times` — `analytics:read` + analítica avanzada en
  el plan (402 `plan_limit_reached` si no). Mismos parámetros y respuesta que en la app
  (`providers[]`, `from`/`to`; docs/05, «Mejores horarios para publicar»).

Los listados aceptan `per_page` (1–100, 30 por defecto). Los borradores creados por
API o MCP pasan por el mismo `ContentService` que la app: quedan en la auditoría de la
organización con `via` (`api` | `mcp`), `api_key_id` y `api_key_name` (nunca el secreto).

### Servidor MCP
`POST /api/public/v1/mcp` — JSON-RPC 2.0 sobre la misma API y autenticación por
key. Métodos: `initialize`, `ping`, `tools/list` (filtra herramientas por los
scopes de la key), `tools/call`. Herramientas: `list_brands`, `list_content`,
`create_content`, `get_analytics` y `get_best_times` (franjas recomendadas con día,
hora de la marca y mejora, y sus próximas fechas en los `days` indicados, 7 por
defecto; sin el mapa de calor), cada una con su scope. Los errores de una
herramienta vuelven como `isError` con un mensaje para el usuario (validación, marca
inexistente); los fallos internos se registran y nunca exponen detalles.

### Webhooks salientes firmados (módulo `Webhooks`)
Cada Organization suscribe **endpoints** (máx. 10) a eventos de dominio y recibe un
POST firmado al ocurrir. Se gestionan en **API y accesos → Webhooks** con el permiso
`api.manage` y el plan con `feature.api` (middleware `EnsureWebhooksEnabled`: 403 / 402
antes de validar). La acción `webhook` de Automations sigue existiendo para reglas
puntuales con condiciones.

**Eventos** (`WebhookEvent`): `content.submitted`, `content.approved`,
`content.changes_requested`, `content.published` (todas o algunas redes, con el
resultado por destino), `content.failed`, `publication.deleted` (una publicación se
borró de su red desde Loop7: contenido, red, destino y fechas), `inbox.message_received`,
`social.connection_expired`; y `webhook.test`, que sólo envía el botón «Probar». El
listener `SendDomainWebhooks` traduce los eventos de dominio sin que los módulos que
los emiten conozcan a Webhooks. Los datos usan sólo identificadores públicos.

**Formato y firma** — especificación abierta [Standard Webhooks](https://www.standardwebhooks.com/)
(el receptor puede usar sus librerías):
- Cuerpo: `{"type": "content.published", "timestamp": "…", "data": {"organization": {…}, …}}`.
- Cabeceras: `webhook-id` (`msg_<ulid>`, el mismo en reintentos y reenvíos: sirve para
  descartar duplicados), `webhook-timestamp` (segundos Unix del intento) y
  `webhook-signature` = `v1,<base64(HMAC-SHA256(clave, "{id}.{timestamp}.{cuerpo}"))>`,
  donde la clave son los bytes del secreto `whsec_<base64>` decodificado. El receptor
  debe rechazar marcas de tiempo con más de 5 minutos de diferencia.
- El secreto se genera al crear el endpoint, se muestra **una sola vez** y se guarda
  **cifrado** (cast `encrypted`); después sólo se ve enmascarado (`whsec_••••abcd`).
  «Renovar secreto» genera otro y durante 24 h los mensajes llevan las dos firmas
  (separadas por espacio) para cambiarlo sin perder mensajes.

**Entrega** (`WebhookDeliverer`, job `DeliverWebhook` en la cola **`webhooks`**):
- Anti-SSRF: la URL debe ser `https://` y pública (`OutboundUrl`), se revalida en cada
  intento, la conexión se fija a la IP validada y **no se siguen redirecciones** (una
  3xx cuenta como fallo con un mensaje claro).
- Éxito = 2xx en ≤ 10 s. Si falla: hasta 7 intentos (inmediato y a 1 min, 5 min,
  30 min, 2 h, 6 h y 12 h; ~21 h) reprogramados con `release()`, así un receptor caído
  no llena `failed_jobs`. La entrega es idempotente (una ya resuelta no se reenvía).
- Tras 15 entregas fallidas seguidas el endpoint se **desactiva**, se audita
  (`webhook.endpoint_disabled`) y se avisa a quien tiene `api.manage` (categoría de
  aviso «Integraciones»). Reactivarlo pone el contador a cero.
- Registro por entrega (`webhook_deliveries`): estado, intentos, código y primeros
  1000 bytes de la respuesta, error y duración; se purga a los 30 días
  (`webhooks:prune`, diario). Desde la UI: filtrar, ver el cuerpo enviado y **reenviar**
  (entrega nueva con el mismo `webhook-id`).
- Si el plan deja de incluir la API o se elimina la organización, no se envía nada más.

**Auditoría**: `webhook.endpoint_created|updated|deleted|disabled`,
`webhook.secret_rotated`, guardando sólo el **dominio** de la URL (la ruta puede ser
una credencial del receptor, p. ej. los «catch hooks» de Zapier).

**Endpoints (panel)**: `GET|POST /webhooks`, `PATCH|DELETE /webhooks/{id}`,
`POST /webhooks/{id}/rotate-secret`, `POST /webhooks/{id}/test`,
`GET /webhooks/{id}/deliveries?status=`, `POST /webhooks/{id}/deliveries/{delivery}/redeliver`
(prueba y reenvío limitados a 10/min).

### Frontend
Vista **API y accesos** (`/app/api-keys`) con dos pestañas (la activa queda en
`?tab=`):
- **API keys**: crear key (muestra el secreto una vez con copiar), lista con scopes y
  último uso, revocar; y referencia de base URL + MCP.
- **Webhooks**: alta/edición (URL, descripción, eventos), secreto una sola vez,
  activar/pausar, «Probar» (envío inmediato con el resultado), «Renovar secreto»,
  eliminar, historial de entregas con filtro, cuerpo enviado, respuesta y «Reenviar», y
  ayuda para verificar la firma (Node con `standardwebhooks` y PHP sin librerías).
