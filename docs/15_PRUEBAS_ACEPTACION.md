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

## Recorrido de punta a punta
`tests/Feature/EndToEnd/MainJourneyTest.php` recorre por la API, en una sola prueba, el
flujo mínimo que exige CLAUDE.md: registro (organización + OWNER + prueba), login,
onboarding del dashboard, crear marca, conectar una cuenta simulada (`fake`), crear un
post con su variante, enviarlo a revisión (la creadora no puede aprobar), aprobarlo con
un APPROVER, programarlo y que el scheduler lo publique (target y contenido
`published`, auditado). Se ejecuta con el resto de la suite:
`XDEBUG_MODE=off php artisan test`.
