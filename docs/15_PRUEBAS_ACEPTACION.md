# Pruebas y criterios de aceptación

## Seguridad tenant
- Usuario A nunca puede consultar/editar/eliminar recursos de Organization B.
- Cambiar un ID/ULID en URL no evade autorización.
- No hay tokens/secrets en respuestas API.

## Roles
- CONTENT_CREATOR puede crear pero no publicar.
- APPROVER puede aprobar pero no gestionar OAuth.
- PUBLISHER puede publicar contenido aprobado según política.
- BILLING no accede a contenido si no tiene permisos adicionales.

## Billing
- gateway disabled no aparece disponible.
- test/prod separados.
- webhook duplicado no duplica cobro ni suscripción.
- plan limita brands/social accounts/members/AI.

## Publishing
- cada red se procesa independientemente.
- fallo de una red produce PARTIAL sin duplicar las publicadas.
- retry no genera post duplicado cuando provider permite identificar idempotencia.

## Auditoría
Toda acción crítica registra actor, contexto, recurso, timestamp e IP/user-agent cuando corresponda.
