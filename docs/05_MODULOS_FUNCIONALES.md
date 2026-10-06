# Módulos funcionales

## Autenticación y cuenta
Registro, login, email verification, reset password, MFA, sesiones, perfil, preferencias, idioma.
Inicio de sesión único SAML 2.0 por organización (ver «SSO — Implementación» y docs/03).

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
Historias implementadas en Instagram y Páginas de Facebook (una imagen o un video, sin texto; docs/06).

## Variantes multicanal
Una pieza base puede producir variantes por red. Las variantes pueden editarse de forma independiente.

## Campaigns
Agrupar contenido por objetivo, rango de fechas, Brand, etiquetas y estado.

## Calendar
Mes/semana/día/lista, drag and drop, filtros, timezone por Organization/Brand, mejores horarios futuros.

## Approval Workflow
Draft -> Review -> Changes Requested -> Approved -> Scheduled -> Publishing -> Published/Partial/Failed
-> Retirado, si después se borra de todas las redes (docs/12, «Borrar de las redes lo publicado»).

## Media Library
Folders, tags, búsqueda, dimensiones, tipo, peso, metadatos, uso por contenido y límites por plan.

## Inbox
Donde APIs lo permitan: conversaciones/comentarios, asignación, respuesta, etiquetas, notas, resuelto, IA de respuesta.

## Analytics
Snapshots, comparación de periodos, performance por post/canal/Brand/campaña, exportación y reportes.

## Competencia
Cuentas públicas de la competencia en las redes cuya API oficial lo permite, con una foto
diaria de sus métricas y la comparación con la marca (ver «Competencia — Implementación»).

## Automation
Disparador (eventos internos, webhook entrante o RSS) + flujo de pasos (acciones, esperas y
condiciones con caminos «Sí»/«No») que se arma en un editor visual.

## Notifications
In-app, correo, push del navegador y WhatsApp según las preferencias de cada usuario y la
configuración de la plataforma (ver «Notifications — Implementación»).

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
diario; `analytics:demo {brand} --days=N` para poblar datos de muestra (con `--history`,
además 60 días de publicaciones medidas para los mejores horarios; ver docs/00).

### Consultas y dashboards
`AnalyticsQueryService`: KPIs, series por fecha, comparación con el periodo anterior
(deltas), desglose por canal y top de publicaciones. Todo acotado por Brand dentro
de la Organization.

### Endpoints
- `GET /api/v1/brands/{brand}/analytics/overview` — permiso `analytics.view`.
- `POST /api/v1/brands/{brand}/analytics/sync` — permiso `analytics.view`.
- `GET /api/v1/brands/{brand}/analytics/export` (CSV) — permiso `analytics.export`
  + entitlement `feature.analytics_advanced` (402 si el plan no lo incluye).
- `GET /api/v1/brands/{brand}/analytics/best-times` — permiso `analytics.view` +
  entitlement `feature.analytics_advanced` (402). Ver «Mejores horarios para publicar».

### Frontend
Vista **Analítica** (`/app/analytics`): selector de marca y de rango (7/30/90 días),
KPIs con comparación de periodo, gráfico de evolución (SVG), desglose por canal, mejores
horarios para publicar, top de publicaciones, actualización manual y exportación CSV
(según plan).

### Mejores horarios para publicar
`GET /brands/{brand}/analytics/best-times` con `providers[]` (redes a considerar; vacío =
todas) y `from`/`to` (rango de las fechas sugeridas; por defecto la semana próxima, máximo
62 días).

- **Datos**: el último snapshot de cada publicación de la marca publicada en los últimos
  90 días y con al menos 48 h de vida (métricas ya asentadas), agrupadas por día de la
  semana y hora **en la zona horaria de la marca** (respeta el cambio de horario).
- **Cálculo** (`BestTimesCalculator`, dominio sin base de datos): cada publicación se
  compara con lo habitual de su cuenta (la mediana de sus interacciones; si es 0, la
  media), con tope de 3 veces, así una cuenta grande no tapa a una pequeña ni un viral
  decide solo. Cada franja promedia esas puntuaciones con suavizado a las horas vecinas
  (peso 0,5) y contracción hacia lo habitual (peso 2): pocas publicaciones no deciden.
- **Recomendados**: hasta 5 franjas con al menos una publicación propia y +5 % sobre lo
  habitual, sin horas seguidas. Hacen falta 10 publicaciones con interacciones en su
  cuenta; si no, `sufficient: false` con el avance (`sample` de `min_posts`).
- **Respuesta**: `timezone`, `sample`, `min_posts`, `sufficient`, `heatmap` (7×24; 1 = lo
  habitual, null = sin datos), `counts` (7×24), `top` (`weekday` 1–7 ISO, `hour`, `lift` en
  %, `posts`) y `occurrences`: próximas fechas de las franjas recomendadas dentro del
  rango, como instantes ISO 8601 (nunca en los próximos 10 minutos).
- **Interfaz** (composable `useBestTimes`: sólo consulta si el plan lo incluye y el
  usuario ve la analítica): tarjeta en **Analítica** con mapa de calor, leyenda,
  recomendados, filtro por red, avance si faltan datos e invitación a mejorar el plan si
  no lo incluye; en el **Calendario** (Semana y Día) esas horas aparecen con ★ y su mejora;
  en el **detalle del contenido**, las próximas fechas para sus redes rellenan el campo de
  programación con un clic.

---

## Competencia — Implementación

### Qué redes y por qué
Sólo APIs oficiales, nunca scraping (backlog: «donde datos/APIs lo permitan»). Contrato
`CompetitorSource` (`Competitors/Contracts`) con una fuente por red:

| Red | API | Qué da | Requisito |
|---|---|---|---|
| Instagram | Business Discovery (API de Instagram con inicio de sesión de Facebook) | Seguidores, nº de publicaciones y las 25 últimas con «me gusta», comentarios y vistas | Cuenta **profesional**; se consulta desde una cuenta de Instagram conectada (`instagram_basic`) |
| Facebook | Page Public Metadata Access | Seguidores («me gusta» si no hay seguidores) de la página | Función de la app aprobada en la revisión de Meta |
| Threads | Profile Discovery (`/profile_lookup`) | Seguidores y totales de 7 días («me gusta», citas, republicaciones, vistas) | Permiso `threads_profile_discovery` (opcional en SUPERADMIN, acceso avanzado) y perfil público con ≥ 100 seguidores; 1000 consultas/24 h |
| Red de prueba | — | Datos deterministas (`noexiste` simula una cuenta que no se encuentra) | Proveedor `fake` activo (fuera de producción) |

No se ofrecen: **YouTube** (sus políticas impiden guardar estadísticas de canales ajenos más
de 30 días y calcular métricas derivadas), **TikTok** y **LinkedIn** (sin API para cuentas
ajenas) ni **X** (cada lectura tiene coste; pendiente de decidir).

### Modelo y sincronización
`competitors` (de una marca) → `competitor_accounts` (red + usuario normalizado, estado y
último error) → `competitor_snapshots` (una foto por día: seguidores, publicaciones, totales
de 7 días) y `competitor_posts` (últimas 50 con interacciones). La consulta se hace con la
cuenta propia conectada de esa red (de la misma marca si la hay; si no, de otra marca de la
organización): su token nunca sale del servidor. Al añadir una cuenta se busca en la red
**antes** de guardarla (el error sale al momento, por cuenta). `competitors:sync-due` (diario,
06:15) encola `SyncCompetitorAccount` (cola `analytics`, escalonado 3 s) para las cuentas sin
foto de hoy de organizaciones cuyo plan lo incluye; un fallo queda en la cuenta (`error`,
`last_error`, `failures`) y se reintenta al día siguiente. Retención: fotos 400 días,
publicaciones 120 días. Al borrar la marca o el competidor se borra todo en cascada.

### Comparación
`CompetitorBenchmark` (7, 30 o 90 días; todas las redes o una) compara las cuentas propias
(de la analítica: seguidores de `account_metric_snapshots`, publicaciones del periodo y su
última medición) con las de la competencia: seguidores, variación absoluta y %,
publicaciones, **interacción media** («me gusta» + comentarios por publicación, lo comparable
entre cuentas) y **tasa de interacción** (interacción media / seguidores). Facebook y Threads
no dan publicaciones: esas columnas quedan vacías (Threads muestra sus totales de 7 días).
Incluye la serie diaria de seguidores y las 10 publicaciones de la competencia con más
interacción.

### Plan, permisos y auditoría
Entitlement `competitor_accounts.max` (Starter 0, Growth 3, Professional 10, Agency 30,
Enterprise 100; 402 `plan_limit_reached`). Ver exige `analytics.view` (y acceso a la marca);
gestionar, `analytics.competitors`. Auditoría `competitor.created|updated|deleted` y
`competitor.account_added|account_removed`.

### Endpoints
- `GET /brands/{brand}/competitors` — competidores con sus cuentas, redes disponibles (con
  el motivo si no) y uso del plan.
- `GET /brands/{brand}/competitors/benchmark?days=30&provider=` — comparación, serie y top.
- `POST /brands/{brand}/competitors` (`name`, `accounts[]: {provider, handle}`, hasta 6),
  `PATCH|DELETE /brands/{brand}/competitors/{competitor}`,
  `POST|DELETE /brands/{brand}/competitors/{competitor}/accounts[/{account}]` y
  `POST /brands/{brand}/competitors/{competitor}/sync` (actualizar ahora; 10/min).

### Frontend
**Competencia** (`/app/competitors`, en Gestión): marca, periodo y red; tabla «Tú frente a
tu competencia» ordenable por columna (tus cuentas marcadas «Tú»), gráfico de crecimiento de
seguidores en % (comparable entre cuentas de tamaños distintos), «Lo que mejor les funciona»
(publicaciones con más interacción) y la lista de competidores con sus cuentas, estado y
acciones (actualizar, añadir cuenta, renombrar en línea, quitar). Sin el plan, invitación a
ver planes; las redes no disponibles explican por qué.

## SSO — Implementación

Módulo `Sso` (onelogin/php-saml 4.x + xmlseclibs, MIT). Las decisiones de seguridad están
en docs/03 («Inicio de sesión único»).

### Modelo
`organization_domains` (dominio, token del registro TXT, `verified_at` y `verified_domain`
—copia única sólo si está verificado—; hasta 10 por organización) y `sso_connections` (una
por organización: activada, obligatoria, Entity ID y URL de inicio de sesión del IdP,
certificados PEM, alta automática y rol por defecto, atributos opcionales del correo y el
nombre, último acceso). El certificado del IdP es público: no se cifra.

### Datos para el IdP
Por organización: Entity ID = URL de metadatos `{APP_URL}/api/v1/sso/{org}/metadata` y ACS
`{APP_URL}/api/v1/sso/{org}/acs` (HTTP-POST). La petición al IdP va por HTTP-Redirect, sin
exigir formato de NameID. El correo sale del atributo configurado o, si no, de los habituales
(`email`, `mail`, `emailaddress`, el claim de Entra ID, el OID LDAP…) y por último del
NameID; el nombre, de `displayName`/`name`… o nombre + apellidos.

### Flujo
1. **Login** «Continuar con SSO»: el SPA crea el verificador, `POST /sso/discover` (`email`,
   `challenge` = SHA-256 hex del verificador) → `redirect_url` del IdP (404
   `sso_not_available` si el dominio no tiene SSO activo).
2. El IdP envía la respuesta a `POST /sso/{org}/acs` → 303 a `/sso/callback#code=…` (o a
   `/login?sso_error=<motivo>`: `expired`, `not_enabled`, `invalid_response`, `no_email`,
   `domain_not_verified`, `not_member`, `suspended`, `seats`, `blocked`, `platform_admin`).
3. `POST /sso/exchange` (`code`, `verifier`) inicia la sesión del SPA y devuelve la
   organización con la que entró (el SPA la selecciona).
4. **Prueba de conexión** (`POST /organization/sso/test`): mismo viaje pero el ACS no inicia
   sesión; vuelve a `/app/settings#sso_test=<token>` y `GET /organization/sso/test/{token}`
   (sólo para quien la hizo, 10 min) muestra si funcionó, el detalle técnico si falló, el
   correo, el NameID, los atributos recibidos y qué pasaría al entrar (miembro o alta).
   Funciona aunque el SSO aún no esté activado.

### Endpoints de configuración (organización actual, `organization.update`, plan con `feature.sso`)
- `GET /organization/sso` — disponibilidad, datos para el IdP, conexión (con asunto y
  caducidad de cada certificado) y dominios con su registro TXT.
- `PUT /organization/sso` — conexión. Activar exige Entity ID, URL https y certificado
  válidos y un dominio verificado; obligatorio exige activado; el rol por defecto debe poder
  asignarlo quien guarda.
- `POST /organization/sso/metadata` (`xml`) — lee los metadatos del IdP pegados (no se
  descargan de URLs) y devuelve los campos sin guardar.
- `POST /organization/sso/domains` (`domain`: acepta correo o URL), `POST
  /organization/sso/domains/{id}/verify` (consulta el TXT; 10/min) y `DELETE
  /organization/sso/domains/{id}` (no el último verificado con el SSO activo).

### Frontend
**Login**: botón «Continuar con SSO» (correo de trabajo) y, si la contraseña devuelve
`sso_required`, cambia a ese modo; muestra los `sso_error` traducidos. **`/sso/callback`**
canjea el código y lo borra de la URL. **Configuración → Inicio de sesión único (SSO)**
(con `organization.update`): datos para el IdP con botón copiar, dominios con su valor TXT y
«Verificar», importar metadatos XML, conexión, atributos, alta automática con rol por
defecto, activar / hacer obligatorio, «Probar conexión» y el resultado de la prueba.

## Inbox — Implementación (Fase 9)

### Contrato de proveedor
`SocialProviderInterface` incorpora `fetchConversations(...): InboxThread[]` y
`replyToConversation(...): InboxReplyResult` (DTOs `InboxThread`/`InboxMessageData`/
`InboxReplyResult`). `FakeSocialProvider` devuelve conversaciones de muestra
(comentario/DM/mención); Facebook e Instagram sincronizan comentarios y responden con
el token de página, y Google Business Profile trae las reseñas (tipo `review`, con
estrellas) y las responde (ver docs/06).

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

### Disparadores externos
- `webhook.received` — **webhook entrante**: al guardar la regla se genera una URL
  secreta `POST /api/v1/hooks/automations/{token}` (48 caracteres aleatorios; en BD el
  token va **cifrado** y se busca por su hash SHA-256). Sólo quien puede editar la
  regla ve la URL; «Renovar URL» invalida la anterior al momento (auditado). Acepta
  JSON o formulario (≤ 64 KB) y responde `202`; los campos se aplanan con puntos
  (`{cliente.nombre}`; listas simples → «a, b»; máx. 4 niveles, 100 campos y 2000
  caracteres por valor) para condiciones y variables. Respuestas: `404` token
  desconocido, `409` regla pausada, `402` plan sin automatizaciones, `413` cuerpo
  grande. **Idempotente** con `Idempotency-Key` (o `webhook-id`) durante 24 h. Límite
  por URL: 60/min y 5000/día.
- `rss.item_published` — **feed RSS/Atom** (`trigger_config.feed_url`):
  `automations:poll-feeds` (cada 5 min) encola `PollRssFeed` para los feeds con más de
  15 min sin revisar (60 min tras 4 errores seguidos). `FeedReader` lee RSS 2.0,
  RSS 1.0 (RDF) y Atom: anti-SSRF en cada salto (máx. 3 redirecciones revalidadas),
  10 s, 2 MB, petición condicional (`ETag`/`Last-Modified` → 304) y XML sin entidades
  ni DTD externas. La **primera lectura sólo memoriza** las entradas existentes; en las
  siguientes, cada entrada nueva dispara la regla (máx. 5 por lectura, de la más
  antigua a la más reciente). Variables: `title`, `link`, `summary` (texto plano),
  `author`, `published_at`, `image`, `feed_title`. El error de la última lectura se
  muestra en la regla; cambiar de feed empieza de cero. «Probar feed»
  (`POST /automations/feed-preview`, 10/min) enseña el título y las últimas entradas
  antes de guardar.

### Flujo (editor visual)
Cada regla tiene un **flujo de pasos** (`automations.flow` = `{steps: [...]}`) en lugar de la
antigua lista de condiciones en Y + acciones (la migración convirtió cada regla en su
equivalente: una condición con las acciones en «Sí»). Tipos de paso:

- **Acción** `{id, type: action, action, config}` — reutilizan módulos o efectos externos, con
  tokens `{campo}` del contexto:
  - `notify` — **Avisar al equipo**: aviso in-app (y por correo/push/WhatsApp según
    preferencias) a una audiencia (`managers`, `approvers`, `publishers`, `team`) con acceso
    a la marca del evento; enlaza al contenido o la conversación.
  - `webhook` — POST saliente con `{trigger, context}`. **Anti-SSRF**
    (`App\Support\Security\OutboundUrl`): sólo http(s) hacia servidores públicos; se
    rechazan localhost, IPs privadas/reservadas, CGNAT, metadatos de la nube, credenciales
    embebidas y dominios internos. Se valida al guardar y al ejecutar, la conexión se fija a
    la IP validada y no sigue redirecciones.
  - `create_draft` — **Crear borrador** en la marca de la regla (obligatoria) con título y
    texto a partir de las variables. Usa `ContentService` (auditado con `via: automation`);
    exige `content.create` a quien guarda la regla, para que no sea una vía de escalada.
  - `inbox_reply` (respuesta automática vía `InboxService`) e `inbox_tag` (etiquetar): sólo
    con el disparador del inbox.
- **Esperar** `{id, type: wait, amount, unit: minutes|hours|days}` — de 1 minuto a 30 días; no
  puede ser el último paso de su camino.
- **Condición** `{id, type: branch, match: all|any, conditions, yes, no}` — 1 a 10 condiciones
  `{field, operator, value}` (operadores `equals`, `not_equals`, `contains`, `not_contains`,
  `starts_with`, `is_empty`, `is_not_empty`; sin distinguir mayúsculas) combinadas con «se
  cumplen todas» o «se cumple alguna». Sigue por «Sí» o por «No», cada uno con sus pasos. Es
  siempre el **último paso de su lista** (árbol, sin uniones): lo que va después vive en sus
  caminos. Un camino vacío termina ahí (un «No» vacío equivale a un filtro).

Límites: 30 pasos, 10 acciones, 5 esperas y 4 condiciones anidadas. `FlowValidator` valida y
normaliza el flujo al guardar (sólo los campos de cada acción, audiencia por defecto, ids
`^[A-Za-z0-9_-]{1,40}$` únicos —los genera si faltan—) y devuelve los errores **por paso**
(`flow.{id}.{campo}`, p. ej. `flow.a1.url`, `flow.b1.conditions.0.field`; generales en
`flow`) para señalarlos en el diagrama. El contexto incluye `content_id`/`conversation_id` y
`brand_id` públicos. Una automatización de una marca sólo la ve y gestiona quien tiene acceso
a esa marca.

### Ejecución y trazabilidad
Los listeners traducen el evento a `AutomationEngine::dispatchForTrigger`, que verifica el
plan (`feature.automations`), busca reglas activas que coinciden y despacha un job
`RunAutomation` por regla (cola `automations`, 120 s, aísla fallos). Los disparadores externos
usan `dispatchDirect` con su regla. El motor crea la ejecución (`automation_runs`) y recorre
el flujo guardando la **traza por paso** (`steps`: id, tipo, estado, mensaje y camino de cada
condición); una acción que falla detiene el flujo.

- **Esperas**: la ejecución queda `waiting` con `resume_at` y `resume_step` (el id del paso
  siguiente). `automations:resume-waiting` (cada minuto) reclama de forma atómica las
  vencidas (`waiting` → `running`) y despacha `ResumeAutomationRun` (un intento: repetir
  podría duplicar acciones), que continúa la misma traza desde ese paso.
- Al reanudar se **cancela** (`cancelled`) si la regla se pausó o eliminó, si el plan ya no
  incluye automatizaciones o si se quitó el paso siguiente (se busca por id: añadir o mover
  otros pasos mientras espera no la descoloca).
- Estados: `success`, `failed`, `skipped` (terminó sin hacer ninguna acción: no se cumplieron
  las condiciones), `waiting`, `running`, `cancelled`. Sólo las terminadas con acciones o
  fallidas cuentan en `run_count`/`last_run_at`.

Crear, editar, eliminar y renovar la URL de una regla queda en la auditoría (`automation.*`,
con las acciones y el nº de pasos, sin la URL ni el token).

### Probar (simulación)
`POST /automations/simulate` (`trigger`, `brand`, `flow`, `context` opcional; 30/min) valida
el flujo igual que al guardar y lo recorre con datos de ejemplo **sin ejecutar nada** (ni
avisos, ni webhooks, ni borradores, ni ejecuciones): devuelve por qué camino iría cada
condición y, por acción, una vista previa con las variables ya sustituidas. Sin `context` usa
los ejemplos del disparador (`AutomationTrigger::examples()`).

### Endpoints
CRUD `GET|POST /automations`, `GET|PUT|DELETE /automations/{automation}` (`flow` en lugar de
`conditions`/`actions`; `GET` de una regla incluye las 20 últimas ejecuciones con su traza y
`fields`: variables del disparador y las que trajo la última ejecución),
`POST /automations/{automation}/rotate-inbound-url`, `POST /automations/feed-preview`,
`POST /automations/simulate` y `GET /automations/meta` (disparadores con campos y ejemplos,
acciones con los disparadores admitidos, operadores, combinaciones, unidades de espera,
audiencias y límites). Requieren permisos `automations.*` + entitlement `feature.automations`
(402); el alta y la edición validan con `SaveAutomationRequest`. Público:
`POST /hooks/automations/{token}`.

### Frontend
**Automatizaciones** (`/app/automations`): listado con activar/pausar, eliminar y «Nueva
automatización», que ofrece **plantillas** (en blanco, avisar al publicar, revisar al día
siguiente, borrador por cada entrada del blog, responder preguntas de precios, enviar a
Zapier/Make). **Editor visual** (`/app/automations/{id}`, `nueva?plantilla=`):

- Diagrama vertical: disparador, pasos y las condiciones abiertas en dos columnas «Sí»/«No»;
  «+» entre pasos para añadir una acción, una condición o una espera (una condición en medio
  se lleva los pasos siguientes a «Sí»); subir/bajar, duplicar y quitar (al quitar una
  condición se pueden conservar sus pasos de «Sí»); **arrastrar y soltar** un paso en otro
  hueco; zoom con «Ajustar al ancho».
- Panel del paso seleccionado: disparador (marca, feed con «Probar feed» y su estado, URL
  secreta del webhook con copiar y «Renovar URL»), acción (con las variables insertables en el
  cursor), espera y condición. Los errores del guardado se marcan en cada paso.
- **Probar** con datos de ejemplo y **últimas ejecuciones**: cualquiera de las dos se ve
  recorrida sobre el diagrama (camino seguido, resultado y vista previa de cada acción; lo no
  recorrido queda atenuado). Aviso de cambios sin guardar al salir.

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
| Webhook desactivado por fallos repetidos | `api.manage` | Sí |

### Preferencias y retención
En **Mi perfil → Notificaciones** cada usuario elige, por categoría (aprobaciones,
publicaciones con errores, cuentas sociales, facturación, inbox, automatizaciones,
integraciones), qué recibe además por **correo**, **push** y **WhatsApp**; en la app llegan
siempre. Sólo se guardan las elecciones explícitas (`users.notification_preferences`,
canal → categoría); por defecto, correo y push siguen `mailByDefault()` (todo menos inbox
y automatizaciones) y WhatsApp sólo aprobaciones, publicaciones con errores y cuentas
sociales (cada mensaje tiene coste). Los avisos marcados como «sólo app» (p. ej.
«publicado en todas las redes») no salen por ningún canal. `notifications:prune` borra a
diario los avisos leídos de más de 90 días y los no leídos de más de 180. Los enlaces de
los correos y los push incluyen `?org=` para abrir la organización correcta; con marca
blanca, el correo usa el nombre de la organización como remitente.

### Push del navegador (Web Push)
- **Envío**: `minishlink/web-push` (MIT) tras el contrato `WebPushGateway`; contenido
  cifrado `aes128gcm` (RFC 8291) y firma VAPID (RFC 8292), TTL de 24 h, cliente HTTP con
  tiempo límite de 10 s y sin redirecciones. Un job por canal (`WebPushChannel`); un
  fallo se registra y no se reintenta (el aviso ya está en la app).
- **Claves VAPID**: las genera SUPERADMIN en **Canales de aviso**; la privada se guarda
  cifrada (`notification_channels.credentials`) y nunca se expone. Regenerarlas borra
  todas las suscripciones (quedan ligadas a la clave anterior) y se audita.
- **Suscripciones** (`push_subscriptions`, por usuario y navegador; máximo 10, se olvidan
  las menos usadas): sólo se aceptan endpoints `https` de los servicios push de los
  navegadores (FCM, Mozilla, Apple, WNS; `PushEndpoint`) para evitar SSRF, y claves
  P-256/auth válidas. Si otra cuenta registra el mismo navegador, pasa a ser suya; al
  cerrar sesión el SPA la da de baja. Los endpoints que responden 404/410 se borran.
- **Service worker** (`apps/frontend/public/sw.js`): sólo muestra avisos y abre la ruta
  del aviso en el propio origen; no intercepta peticiones. En iPhone requiere añadir la
  app a la pantalla de inicio.
- En Windows, OpenSSL necesita `OPENSSL_CONF` (p. ej. el `extras/ssl/openssl.cnf` de PHP)
  para crear claves EC; sin él, generar claves responde 422 `push_keys_failed`.

### WhatsApp (Cloud API de Meta)
- **Configuración** (SUPERADMIN → Canales de aviso): identificador del número, token de
  un usuario del sistema (cifrado, de solo escritura, se muestra `••••1234`), plantillas e
  idioma (`es_MX` por defecto). «Probar conexión» consulta el número y, con un destino,
  envía un aviso de prueba con la plantilla.
- **Plantillas** a aprobar en WhatsApp Manager: avisos (Utilidad) con `{{1}}`
  organización, `{{2}}` título y `{{3}}` detalle; código (Autenticación) con botón
  «Copiar código». Las variables se envían sin saltos de línea y recortadas.
- **Plan**: entitlement `feature.whatsapp_notifications` (Professional, Agency,
  Enterprise). Decide el plan de la organización del aviso; un usuario puede registrar
  su número si alguna de sus organizaciones lo incluye.
- **Número del usuario**: se verifica con un código de 6 dígitos (HMAC en caché 10 min,
  máximo 5 intentos con incremento atómico, 10 códigos/día por usuario y 5 por número,
  `throttle:3,10`). Se guarda cifrado (`users.whatsapp_phone`) sólo tras confirmarlo;
  verificar y quitar se auditan con el número enmascarado. Nunca se registra en logs.
- Un fallo de envío se registra (sin número ni token) y no se reintenta: repetir podría
  duplicar un mensaje con coste.

### Endpoints
- `GET /notifications` (`unread=1`, paginado; `meta.unread`), `GET /notifications/unread-count`
- `POST /notifications/{id}/read`, `POST /notifications/read-all`
- `GET|PUT /me/notification-preferences` — `PUT` admite `{mail|push|whatsapp: {categoría: bool}}`;
  `GET` incluye `channels` (disponibilidad, clave pública VAPID, nº de navegadores y
  número de WhatsApp enmascarado)
- `POST|DELETE /me/push-subscriptions` (`endpoint`, `keys.p256dh`, `keys.auth`),
  `POST /me/push-subscriptions/test`
- `POST /me/whatsapp` (`phone` E.164), `POST /me/whatsapp/verify` (`code`), `DELETE /me/whatsapp`
- SUPERADMIN: `GET /platform/notification-channels`, `PUT /platform/notification-channels/webpush`,
  `POST /platform/notification-channels/webpush/keys`, `PUT /platform/notification-channels/whatsapp`,
  `POST /platform/notification-channels/whatsapp/test`

### Frontend
Campana con contador (sondeo cada 60 s con la pestaña visible), últimos avisos y
"marcar todo como leído"; página **Notificaciones** (`/app/notifications`) con filtro
"sin leer" y paginación. En **Mi perfil → Notificaciones**: tabla de categorías por
canal, activar push en el navegador actual (con aviso de prueba) y número de WhatsApp
con código. SUPERADMIN: **Canales de aviso** (`/platform/notification-channels`).

---

## Inicio (dashboard)
`GET /dashboard`: primeros pasos reales de la organización (marca, redes, contenido,
equipo, primera publicación), programadas en 7 días, pendientes de aprobación,
publicadas y con errores en la última semana, próximas publicaciones, contenido que
requiere atención, cuentas por reconectar, uso del plan y equipo. Cada bloque depende
del rol y el contenido se limita a las marcas accesibles.

## Calendario
Vistas **Mes**, **Semana**, **Día** y **Lista** (`/app/calendar`). La vista y el día de
referencia viven en la URL (`?vista=mes|semana|dia|lista&fecha=AAAA-MM-DD`): un enlace o
«Atrás» vuelven al mismo punto. Las flechas avanzan un mes, una semana (de lunes a
domingo) o un día, y «Hoy» vuelve a la fecha actual.

- **Semana y Día**: una fila por hora con la línea de «ahora»; al abrir se desplaza a la
  primera publicación del rango (o a las 7:00). La cabecera de cada día (o su número en
  el Mes) abre su vista Día, que muestra además redes, estado y campaña. En móvil la
  semana se desplaza dentro de su tarjeta.
- **Filtros** por red y por estado, sobre lo ya cargado (no consultan de nuevo).
- **Mejores horarios** (con analítica avanzada): en Semana y Día las horas recomendadas
  para la marca (y la red filtrada) aparecen con ★ y su mejora, más marcadas al
  arrastrar. Se pueden ocultar; la preferencia se recuerda en el navegador.
- **Arrastrar para programar** (con `content.schedule`): lo programado se reprograma y lo
  aprobado («Listos para programar») se programa. En el Mes se conserva la hora (lo
  aprobado sale a las 10:00); en Semana y Día se usa la hora de la celda conservando los
  minutos. Si esa fecha ya pasó pero la celda (o el día) aún no termina, sale en la
  siguiente hora en punto; en una celda pasada no se programa. Usa
  `POST /content/{content}/schedule`, con su validación, cupos del plan y de cada red, y
  auditoría. Sin arrastre, se programa desde el detalle del contenido.
- **Zona horaria**: las horas se muestran en la del navegador, indicada en pantalla (si
  la marca tiene otra, se avisa al pasar el cursor).
- `GET /brands/{brand}/calendar?from=&to=` (ISO 8601, `content.view`, rango de hasta 62
  días): contenido con fecha de la marca, con estado, redes y campaña. Se consulta sólo el
  rango visible (las semanas del mes, 7 días o 1 día).

## Biblioteca de medios
Por marca: subida (MIME real, tamaño y límite de almacenamiento del plan; sin SVG),
archivos privados servidos por URL firmada, **carpetas** (un nivel; al borrar una
carpeta sus archivos quedan "sin carpeta") y **etiquetas** (se crean al etiquetar y se
eliminan cuando nadie las usa). Listado paginado con filtros por carpeta, etiqueta, tipo
(imagen, vídeo, documento) y búsqueda por nombre. Organizar exige `content.update`,
subir `content.create` y borrar `content.delete`; subida, cambios y borrados se auditan.

- `GET|POST /brands/{brand}/media` (`folder`, `tag`, `type`, `q`, `ids`, `per_page` ≤ 60)
- `PATCH /media/{asset}` (`folder`, `tags`), `DELETE /media/{asset}`
- `GET|POST /brands/{brand}/media/folders`, `PATCH|DELETE /media/folders/{folder}`
- `GET /brands/{brand}/media/tags`

## Marcas: logo, zona horaria y eliminación
- El logo es una imagen de la biblioteca de la propia marca (`PUT /brands/{brand}/logo`).
- **Zona horaria** (la de su audiencia; base de sus mejores horarios para publicar): se
  elige al crearla y en su ficha (`timezone` en `POST /brands` y `PATCH /brands/{brand}`,
  zona IANA válida). Si el alta no la indica, hereda la de la organización. El registro
  guarda la zona del navegador para la cuenta y su organización (si falta o no es
  válida queda UTC; nunca impide el alta). La migración `default_brand_timezone_to_organization`
  pasa a la zona de su organización las marcas que se habían creado en UTC por defecto.
- Eliminar una marca emite `BrandDeleted`: se cancelan sus publicaciones programadas,
  se desconectan sus cuentas sociales (se borran los tokens) y se pausan sus
  automatizaciones, en la misma transacción.

## Marca blanca
Con `feature.white_label`: nombre visible, color principal (contraste AA con texto
blanco) y logo propio (`/organization/branding`, logo por URL firmada). El panel de la
organización adopta su logo, nombre y paleta; los correos usan su nombre.
