# Motor de publicación y colas

## Estados
IDEA, DRAFT, IN_REVIEW, CHANGES_REQUESTED, APPROVED, SCHEDULED, PUBLISHING, PUBLISHED, PARTIAL, FAILED, CANCELLED, EXPIRED.

## Granularidad
Un Content Item puede tener múltiples `PostVariant` y múltiples `PublicationTarget`. Cada target mantiene su propio estado e identificador remoto.

## Queues sugeridas
- critical
- publishing
- webhooks
- analytics
- media
- ai
- notifications
- default

## Jobs
- PublishSocialPost
- RefreshSocialToken
- SyncPostMetrics
- SyncAccountMetrics
- ProcessProviderWebhook
- GenerateAIContent
- ProcessMedia
- SendNotification

## Resiliencia
- retries configurables;
- exponential backoff;
- timeout;
- unique jobs/idempotency keys;
- rate limit middleware por provider;
- circuit breaker conceptual para outages recurrentes;
- dead-letter/failed jobs operables desde SUPERADMIN.

## Implementación (Fase 6)

### Motor
`App\Modules\Content\Services\PublishingService` ejecuta cada `PublicationTarget`
de forma **independiente e idempotente**:
- `publishTarget($target, $finalAttempt)` corta si el target ya está `PUBLISHED` o tiene
  `remote_id` (idempotencia), marca `PUBLISHING` y registra cada intento en
  `publication_attempts`. Si el proveedor falla:
  - con reintentos pendientes (`$finalAttempt = false`) guarda el error pero el target
    **sigue `PUBLISHING`**: el contenido no se da por fallido mientras el reintento puede
    resolverlo; relanza la excepción para que el job reintente;
  - en el último intento marca `FAILED` y consolida.
  - Token caducado/revocado: marca la conexión como expirada y falla sin reintentar
    (hay que reconectar).
- `rollup()` consolida el estado del `ContentItem` según sus targets:
  `PUBLISHED` (todos), `FAILED` (ninguno) o `PARTIAL` (parcial). Es **idempotente**: si el
  contenido ya tiene ese estado no vuelve a auditar ni a emitir eventos (el último
  intento y `failed()` del job consolidan el mismo resultado). Emite `ContentPublished`
  (published/partial) o `ContentPublicationFailed` (failed) para Automations y
  Notifications.
- `publishNow()` crea los targets faltantes en una transacción y despacha un job por target.
- `dispatchDue()` despacha los targets `SCHEDULED` cuya fecha ya venció.
- Al eliminar una marca (`BrandDeleted`) se cancelan sus targets pendientes y su
  contenido programado.

### Job
`App\Modules\Content\Jobs\PublishSocialPost`: `tries = 3`, `backoff = [10, 30, 60]`,
middleware `WithoutOverlapping('publish-target-{id}')->dontRelease()` +
`RateLimited('social-publish')` (60/min, ver `AppServiceProvider`). Pasa
`finalAttempt = attempts() >= tries` al servicio; en `failed()` marca el target como
`FAILED` y consolida.

### Scheduler
Comando `content:publish-due` (registrado en `ContentServiceProvider`) programado
`everyMinute()->withoutOverlapping()`; invoca `dispatchDue()`.

### Endpoint
`POST /api/v1/content/{content}/publish-now` — permiso `content.publish_now`.
Sólo permite publicar contenido en estado `APPROVED` o `SCHEDULED`.

### Proveedores
`SocialProviderInterface::publish(OAuthTokens, destinationExternalId, PublishPayload, credentials): PublishResult`.
El proveedor `fake` publica de forma idempotente (hash de `idempotencyKey`) y simula
fallos si el cuerpo contiene `[[FAIL]]`; `FacebookProvider::publish()` lanza
`ProviderNotConfiguredException` hasta contar con Page Token + App Review.

### Operación
- **Producción / Linux:** Redis + Horizon (`php artisan horizon`) + `php artisan schedule:run` por cron.
- **Desarrollo / Windows:** driver `database` con `php artisan queue:work` y
  `php artisan schedule:work`. Horizon requiere `ext-pcntl` (no disponible en Windows).
- **Fallos:** panel SUPERADMIN en `/platform/jobs` (reintentar / descartar) — cross-platform.
  Ver [09_SUPERADMIN.md](09_SUPERADMIN.md).
