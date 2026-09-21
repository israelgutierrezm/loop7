# Observabilidad y calidad

## Logging
JSON estructurado en producción, correlation_id, organization_id cuando sea seguro, provider, operation, status. Nunca secretos.

## Métricas
- requests
- latencia API
- errores 4xx/5xx
- queue depth
- job latency/failures
- publicaciones por provider
- OAuth failures
- webhook failures
- payment failures
- AI cost/usage

## Alertas
- cola publishing atrasada;
- subida de errores por provider;
- webhooks inválidos;
- fallos de renovación de token;
- billing webhook failure;
- almacenamiento/DB/Redis.

## Calidad
- PHPStan/Larastan nivel alto progresivo.
- Laravel Pint.
- ESLint + TypeScript strict.
- Prettier si se adopta, sin conflicto con ESLint.
- tests en CI.
