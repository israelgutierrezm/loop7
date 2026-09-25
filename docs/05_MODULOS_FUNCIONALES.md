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
devuelve métricas deterministas de muestra; Facebook e Instagram consultan las métricas
vigentes de Graph API (ver docs/06).

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
(comentario/DM/mención); Facebook e Instagram sincronizan comentarios y responden con
el token de página (ver docs/06).

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
- `GET /brands/{brand}/inbox` (filtros `status`, `assigned_to_me`), `POST /brands/{brand}/inbox/sync`
- `GET /brands/{brand}/inbox/assignees`: miembros a los que se puede asignar (permiso de
  inbox y acceso a la marca); `assign` rechaza a cualquier otro (422).
- `GET /inbox/{conversation}` (marca como leída)
- `POST /inbox/{conversation}/reply|note|assign|status|suggest`, `PUT .../tags`
- Todo requiere permiso `social_accounts.inbox` + entitlement `feature.inbox` (402
  si el plan no lo incluye) y acceso a la marca de la conversación.

### Frontend
Vista **Inbox** (`/app/inbox`): lista con filtros por estado y "solo mías", hilo de
conversación (entrante/respuesta/nota), responder, **sugerir con IA**, notas
internas, asignación a cualquier compañero con acceso, etiquetas, cambio de estado y
sincronización manual. `?brand=&conversation=` abre directamente una conversación
(enlace de los avisos).

---

## Automation — Implementación (Fase 10)

### Motor de reglas
`Automation` = trigger + condiciones + acciones (tenant-owned; global de la
Organization o acotada a una Brand). El motor está **desacoplado por eventos**
(CLAUDE.md): cada módulo emite eventos de dominio y Automations los escucha, sin
que esos módulos conozcan a Automations.

### Disparadores (eventos internos)
- `content.published` ← evento `ContentPublished` (emitido por `PublishingService`
  al consolidar published/partial).
- `inbox.message_received` ← evento `InboxMessageReceived` (emitido por
  `InboxService` al llegar un mensaje entrante nuevo).

RSS/webhooks entrantes quedan previstos para una fase posterior.

### Condiciones y acciones
- Condiciones: lista de `{field, operator, value}` (operadores equals/not_equals/
  contains/not_contains) evaluadas en AND contra un contexto plano del disparador.
- Acciones (reutilizan módulos o efectos externos), con tokens `{campo}` del contexto:
  - `notify` — **Avisar al equipo**: aviso in-app (y por correo según preferencias) a
    una audiencia (`managers`, `approvers`, `publishers`, `team`) con acceso a la marca
    del evento; enlaza al contenido o la conversación.
  - `webhook` — POST saliente. **Anti-SSRF** (`App\Support\Security\OutboundUrl`): sólo
    http(s) hacia servidores públicos; se rechazan localhost, IPs privadas/reservadas,
    CGNAT, metadatos de la nube, credenciales embebidas y dominios internos. Se valida
    al guardar y al ejecutar, la conexión se fija a la IP validada y no sigue
    redirecciones.
  - `inbox_reply` (respuesta automática vía `InboxService`), `inbox_tag` (etiquetar).
- La configuración de cada acción se valida al guardar (errores por campo).
- El contexto incluye los identificadores públicos `content_id`/`conversation_id` y
  `brand_id` (útiles para enlazar avisos y para integraciones por webhook).
- Una automatización de una marca sólo la ve y gestiona quien tiene acceso a esa marca.

### Ejecución y trazabilidad
Los listeners traducen el evento a `AutomationEngine::dispatchForTrigger`, que
verifica el plan (`feature.automations`), busca reglas activas que coinciden y
despacha un job `RunAutomation` por regla (cola `automations`, aísla fallos). El
motor evalúa condiciones y ejecuta acciones, registrando cada intento en
`automation_runs` (success/failed/skipped) y actualizando `run_count`/`last_run_at`.

### Endpoints
CRUD `GET|POST /automations`, `GET|PUT|DELETE /automations/{automation}` y
`GET /automations/meta` (catálogo de triggers/acciones/operadores/audiencias para la
UI). Requieren permisos `automations.*` + entitlement `feature.automations` (402).

### Frontend
Vista **Automatizaciones** (`/app/automations`): listado con activar/pausar y
eliminar, y un editor (modal) con disparador, marca, condiciones y acciones
dinámicas según el tipo (mensaje y audiencia del aviso, URL del webhook…), con la
lista de variables disponibles del disparador.

---

## Notifications — Implementación

### Modelo
Canal `database` de Laravel con tabla `notifications` + `organization_id`
(`OrganizationDatabaseChannel`): un usuario puede pertenecer a varias organizaciones y la
campana muestra sólo los avisos de la actual. Un único tipo de aviso,
`OrganizationNotice` (en cola, `afterCommit`), con `kind`, categoría, nivel
(info/success/warning/danger), título, texto y ruta del SPA; el texto se compone al
crearlo (no depende de que el recurso siga existiendo).

### Quién recibe qué
El módulo escucha eventos de dominio (`SendDomainNotifications`) y sólo avisa a miembros
activos con el permiso indicado y acceso a la marca (`MembershipService`):

| Evento | Destinatarios | Correo |
|---|---|---|
| Contenido enviado a revisión | `content.approve` (menos quien lo envió) | Sí |
| Aprobado / cambios solicitados | Autor y quien lo envió (menos quien revisó) | Sí |
| Publicado en todas las redes | Autor y aprobador | No (sólo app) |
| Publicado parcialmente / fallido | Autor y aprobador | Sí |
| Conexión social caducada | `social_accounts.reconnect` | Sí |
| Suscripción (fin de prueba próximo, pago no recibido, suspensión, prueba vencida, cancelación, plan activado) | `billing.view` | Sí (renovación/reanudación sólo app) |
| Conversación asignada | La persona asignada | Según preferencia |
| Automatización "Avisar al equipo" | Audiencia elegida | Según preferencia |

### Preferencias y retención
En **Mi perfil → Notificaciones** cada usuario elige qué categorías recibe además por
correo (aprobaciones, publicaciones con errores, cuentas sociales, facturación, inbox,
automatizaciones); en la app llegan siempre. `notifications:prune` borra a diario los
avisos leídos de más de 90 días y los no leídos de más de 180. Los enlaces de los
correos incluyen `?org=` para abrir la organización correcta; con marca blanca, el
correo usa el nombre de la organización como remitente.

### Endpoints
- `GET /notifications` (`unread=1`, paginado; `meta.unread`), `GET /notifications/unread-count`
- `POST /notifications/{id}/read`, `POST /notifications/read-all`
- `GET|PUT /me/notification-preferences`

### Frontend
Campana con contador (sondeo cada 60 s con la pestaña visible), últimos avisos y
"marcar todo como leído"; página **Notificaciones** (`/app/notifications`) con filtro
"sin leer" y paginación.

---

## Inicio (dashboard)
`GET /dashboard`: primeros pasos reales de la organización (marca, redes, contenido,
equipo, primera publicación), programadas en 7 días, pendientes de aprobación,
publicadas y con errores en la última semana, próximas publicaciones, contenido que
requiere atención, cuentas por reconectar, uso del plan y equipo. Cada bloque depende
del rol y el contenido se limita a las marcas accesibles.

## Marcas: logo y eliminación
- El logo es una imagen de la biblioteca de la propia marca (`PUT /brands/{brand}/logo`).
- Eliminar una marca emite `BrandDeleted`: se cancelan sus publicaciones programadas,
  se desconectan sus cuentas sociales (se borran los tokens) y se pausan sus
  automatizaciones, en la misma transacción.

## Marca blanca
Con `feature.white_label`: nombre visible, color principal (contraste AA con texto
blanco) y logo propio (`/organization/branding`, logo por URL firmada). El panel de la
organización adopta su logo, nombre y paleta; los correos usan su nombre.
