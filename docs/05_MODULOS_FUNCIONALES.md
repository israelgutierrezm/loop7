# Módulos funcionales

## Autenticación y cuenta
Registro, login, email verification, reset password, MFA, sesiones, perfil, preferencias, idioma.

## Onboarding
Wizard: Organization -> plan/trial -> primera Brand -> identidad -> conectar red -> primer contenido.

## Organizations
Datos fiscales/comerciales, miembros, roles, suscripción, usage y seguridad.

## Brands
Nombre, sitio, descripción, logos, colores, tono, públicos, productos, servicios, CTA, hashtags, términos prohibidos, documentos y Knowledge Base.

## Social Connections
Conectar, reconectar, desconectar, listar capacidades/scopes, estado de token, expiración, cuentas/páginas disponibles.

## Content Studio
Texto, imagen, carrusel, story, reel/video, thread, pin, documento y otros formatos según capacidades de red.

## Variantes multicanal
Una pieza base puede producir variantes por red. Las variantes pueden editarse de forma independiente.

## Campaigns
Agrupar contenido por objetivo, rango de fechas, Brand, etiquetas y estado.

## Calendar
Mes/semana/día/lista, drag and drop, filtros, timezone por Organization/Brand, mejores horarios futuros.

## Approval Workflow
Draft -> Review -> Changes Requested -> Approved -> Scheduled -> Publishing -> Published/Partial/Failed.

## Media Library
Folders, tags, búsqueda, dimensiones, tipo, peso, metadatos, uso por contenido y límites por plan.

## Inbox
Donde APIs lo permitan: conversaciones/comentarios, asignación, respuesta, etiquetas, notas, resuelto, IA de respuesta.

## Analytics
Snapshots, comparación de periodos, performance por post/canal/Brand/campaña, exportación y reportes.

## Automation
Trigger + Conditions + Actions. Inicialmente RSS/webhook/eventos internos; posteriormente builder visual.

## Notifications
In-app/email y futuras push/WhatsApp según configuración.

---

## Analytics — Implementación (Fase 8)

### Contrato de proveedor
`SocialProviderInterface` incorpora `fetchAccountMetrics(...): AccountMetrics` y
`fetchPostMetrics(...): PostMetrics` (DTOs agnósticos). `FakeSocialProvider`
devuelve métricas deterministas de muestra; `FacebookProvider` lanza
`ProviderNotConfiguredException` hasta contar con revisión de app y token de página.

### Snapshots (series temporales)
- `account_metric_snapshots`: una fila por destino y día (followers, reach,
  impressions, engagement, posts_count). Único por (destino, fecha).
- `post_metric_snapshots`: una fila por PublicationTarget y día (impressions,
  reach, likes, comments, shares, clicks, engagement). Único por (target, fecha).
- Ambas tenant-owned (aisladas por Organization).

### Sincronización
`MetricsSyncService`: `syncAccount`/`syncPost` (upsert del día, omiten proveedores
sin configurar), `syncBrand` (en el acto), `syncDue` (despacha jobs) y `backfillDemo`
(serie sintética de muestra para desarrollo). Jobs `SyncAccountMetrics` /
`SyncPostMetrics` en la cola `analytics`. Comando `analytics:sync-due` programado a
diario; `analytics:demo {brand} --days=N` para poblar datos de muestra.

### Consultas y dashboards
`AnalyticsQueryService`: KPIs, series por fecha, comparación con el periodo anterior
(deltas), desglose por canal y top de publicaciones. Todo acotado por Brand dentro
de la Organization.

### Endpoints
- `GET /api/v1/brands/{brand}/analytics/overview` — permiso `analytics.view`.
- `POST /api/v1/brands/{brand}/analytics/sync` — permiso `analytics.view`.
- `GET /api/v1/brands/{brand}/analytics/export` (CSV) — permiso `analytics.export`
  + entitlement `feature.analytics_advanced` (402 si el plan no lo incluye).

### Frontend
Vista **Analítica** (`/app/analytics`): selector de marca y de rango (7/30/90 días),
KPIs con comparación de periodo, gráfico de evolución (SVG), desglose por canal, top
de publicaciones, actualización manual y exportación CSV (según plan).

---

## Inbox — Implementación (Fase 9)

### Contrato de proveedor
`SocialProviderInterface` incorpora `fetchConversations(...): InboxThread[]` y
`replyToConversation(...): InboxReplyResult` (DTOs `InboxThread`/`InboxMessageData`/
`InboxReplyResult`). `FakeSocialProvider` devuelve conversaciones de muestra
(comentario/DM/mención); `FacebookProvider` lanza `ProviderNotConfiguredException`
(requiere revisión de app + page token).

### Modelo
- `inbox_conversations`: tenant-owned, dedupe por (conexión, external_id); estado
  (open/pending/resolved/snoozed), asignación, etiquetas, `unread_count`, preview.
- `inbox_messages`: `type` inbound (entrante) | reply (saliente enviada) | note
  (nota interna que no se envía a la red). Dedupe por (conversación, external_id).

### Servicio y jobs
`InboxService`: `syncBrand`/`syncDestination` (upsert idempotente, preserva estado/
asignación al re-sincronizar, `unread_count` sólo por nuevos entrantes), `syncDue`
(despacha jobs), `reply` (envía vía proveedor + guarda mensaje) y `addNote`. Job
`SyncInboxConversations` en la cola `inbox`; comando `inbox:sync-due` cada 15 min.

### Respuestas con IA
Reutiliza la Fase 7: `POST /inbox/{conversation}/suggest` compone un prompt con el
último mensaje entrante + Brand Brain y llama a `AiGenerationService` con la operación
`suggest_reply` (consume créditos, permiso `ai.generate_text`).

### Endpoints
- `GET /brands/{brand}/inbox`, `POST /brands/{brand}/inbox/sync`
- `GET /inbox/{conversation}` (marca como leída)
- `POST /inbox/{conversation}/reply|note|assign|status|suggest`, `PUT .../tags`
- Todo requiere permiso `social_accounts.inbox` + entitlement `feature.inbox` (402
  si el plan no lo incluye).

### Frontend
Vista **Inbox** (`/app/inbox`): lista con filtros por estado y no leídos, hilo de
conversación (entrante/respuesta/nota), responder, **sugerir con IA**, notas
internas, asignación y cambio de estado, y sincronización manual.
