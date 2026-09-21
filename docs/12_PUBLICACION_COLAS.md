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
