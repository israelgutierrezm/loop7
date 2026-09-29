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

## Pruebas E2E en navegador (Playwright)
`apps/frontend/e2e` recorre la **interfaz real** en Chrome/Chromium contra un backend
aislado, como lo haría una persona:
- `auth.spec.ts`: registro con organización (queda como actual), cierre de sesión,
  redirección al login conservando el destino, nuevo inicio de sesión y credenciales
  incorrectas.
- `main-journey.spec.ts`: crear marca → conectar la cuenta simulada (`fake`, vuelve
  directo al callback OAuth) → crear contenido → añadir la variante → enviar a revisión
  y aprobar (o «Marcar como listo» si el plan no tiene aprobaciones) → programar para
  mañana → verlo «Programado» en el listado → encontrarlo con el buscador de comandos
  (Ctrl+K); y «Publicar ahora» hasta «Publicado».
- `calendar.spec.ts`: con una publicación programada, la vista Semana abierta desde la
  URL la muestra en su hora → la cabecera del día abre la vista Día → arrastrarla a otra
  hora la reprograma → filtro por estado → navegación por días → en móvil la página no
  se desborda. El arrastre nativo no se emula de forma fiable en Chrome sin interfaz: la
  prueba emite los eventos HTML5 (`dragstart`, `dragover`, `drop`…) sobre los elementos
  reales.

Los pasos comunes (registro, marca, cuenta simulada, contenido, aprobación, programar)
están en `e2e/support.ts`. Cada prueba crea su propia cuenta (no dependen entre sí). Entorno (`playwright.config.ts`
+ `e2e/start-backend.mjs`): escribe `apps/backend/.env.e2e` (ignorado por git), recrea
`database/e2e.sqlite` (WAL + `synchronous=OFF`) con migraciones y datos base, sirve
Laravel en **8002** y un Vite propio en **5174** (proxy a 8002): no toca la base ni los
puertos de desarrollo. En local usa el Chrome instalado (`E2E_BROWSER_CHANNEL=msedge`
para Edge) y en CI el Chromium de Playwright (job `e2e`, que guarda el informe si
falla). `npm run test:e2e` (o `test:e2e:ui` para depurar).
