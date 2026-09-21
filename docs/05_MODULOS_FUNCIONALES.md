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
