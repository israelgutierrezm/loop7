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
`timeout = 300`, middleware `WithoutOverlapping('publish-target-{id}')->dontRelease()` +
`RateLimited('social-publish')` (60/min, ver `AppServiceProvider`). Pasa
`finalAttempt = attempts() >= tries` al servicio; en `failed()` marca el target como
`FAILED` y consolida.

El timeout cubre la espera al procesamiento de videos de Instagram: hasta 40 consultas
de estado cada 3 s (2 min) por publicación, compartidas por todos sus contenedores (en un
carrusel se crean primero todos los hijos y Meta los procesa en paralelo).

**Reintentos que retoman (checkpoint).** Cada intento recibe un `PublishCheckpoint`
(`PublishPayload::checkpoint`) que se guarda al momento en
`publication_targets.provider_state`, junto con una huella de la variante (texto, formato
y medios): si se edita entre intentos, se empieza de cero. Instagram guarda ahí cada
contenedor creado (`main` o `child:N`, con su firma y fecha) y, en cuanto Meta devuelve
el post, su id. Así un reintento:
- **retoma** el contenedor que Meta seguía procesando (tras consultar su estado) en vez
  de subir otra vez el video; si Meta lo dio por fallido/caducado (o pasaron 23 h, porque
  caducan a las 24) crea otro;
- **no duplica**: si el post ya se publicó y el worker murió antes de registrarlo,
  devuelve ese post sin volver a publicar.
Al publicarse con éxito, `provider_state` se limpia. Por eso
`retry_after` de las colas `database` y `redis` es 330 s (`DB_QUEUE_RETRY_AFTER` /
`REDIS_QUEUE_RETRY_AFTER`): siempre mayor que el timeout más largo, o un segundo worker
retomaría un trabajo que sigue en curso.

Los errores guardados en el target y en `publication_attempts` pasan por
`SecretRedactor`, y `MetaGraph` nunca propaga el mensaje de cURL de un fallo de red
(incluye la URL con el token).

### Scheduler
Comando `content:publish-due` (registrado en `ContentServiceProvider`) programado
`everyMinute()->withoutOverlapping()`; invoca `dispatchDue()`.

### Endpoint
`POST /api/v1/content/{content}/publish-now` — permiso `content.publish_now`.
Sólo permite publicar contenido en estado `APPROVED` o `SCHEDULED`.

### Proveedores
`SocialProviderInterface::publish(OAuthTokens, destinationExternalId, PublishPayload, credentials): PublishResult`.
El proveedor `fake` publica de forma idempotente (hash de `idempotencyKey`) y simula
fallos si el cuerpo contiene `[[FAIL]]`. `FacebookProvider` (Páginas) e
`InstagramProvider` (cuentas profesionales vinculadas a una Página) publican contra Graph
API con el page token del destino; requieren las credenciales de la app de Meta
(SUPERADMIN → Redes sociales) y, para clientes reales, App Review.

### Operación
- **Producción / Linux:** Redis, el contenedor `worker` (`queue:work
  --queue=publishing,default,inbox,analytics,automations`, necesita `ext-pcntl` para
  hacer cumplir los timeouts) y el `scheduler` (`schedule:work`). Ver
  [21_RUNBOOK_OPERACION.md](21_RUNBOOK_OPERACION.md).
- **Desarrollo / Windows:** driver `database` con `php artisan queue:work` y
  `php artisan schedule:work` (sin `pcntl` los timeouts no se aplican).
- **Fallos:** panel SUPERADMIN en `/platform/jobs` (pendientes por cola; reintentar o
  descartar uno a uno o todos). Ver [09_SUPERADMIN.md](09_SUPERADMIN.md).
