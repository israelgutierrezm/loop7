# Estructura del repositorio

Monorepo con backend y frontend separados, servidos en el mismo origen en
producción (Nginx en la imagen Docker).

```
/
  apps/
    backend/          # Laravel 12 (API REST /api/v1, API pública /api/public/v1)
    frontend/         # Vue 3 + TS (SPA)
  docker/             # nginx.conf, php.ini, supervisord.conf, entrypoint.sh
  docs/               # especificación, decisiones y guías
  Dockerfile          # imagen única (SPA + API)
  docker-compose.yml  # app + worker + scheduler + MySQL + Redis
  CLAUDE.md
  README.md
```

## Backend (`apps/backend/app/Modules`)

Cada módulo tiene su `ServiceProvider` (extiende `ModuleServiceProvider`), que
carga `Database/Migrations` y `routes/api.php` (bajo `api/v1`), y puede registrar
comandos y tareas programadas en `bootModule()`.

```
AccessControl   roles, permisos y seeder RBAC
Ai              proveedores de IA, créditos, BYOK, Brand Brain → prompts
Analytics       snapshots de métricas, consultas y exportación
Api             claves de API, API pública y servidor MCP
Audit           auditoría inmutable
Automations     reglas por eventos (triggers, condiciones, acciones)
Billing         planes, entitlements, suscripciones, add-ons, uso
Brands          marcas y Brand Brain
Campaigns       campañas
Compliance      borrado de datos (Meta)
Content         contenido, variantes, aprobación, publicación
Identity        autenticación, perfil, MFA
Inbox           conversaciones y respuestas
MediaLibrary    archivos privados con URLs firmadas
Organizations   organizaciones, miembros, invitaciones, tenancy
Payments        pasarelas, transacciones, facturas, webhooks
PlatformAdmin   panel SUPERADMIN (dashboard, organizaciones, usuarios, colas…)
SocialConnections  redes sociales (OAuth, adaptadores Meta)
```

Código transversal en `app/Support` (tenancy, respuestas API, `HasPublicId`).

## Frontend (`apps/frontend/src`)

```
components/   ui/ (primitivas), layout/, social/, legal/…
config/       navegación
layouts/      AdminLayout, PlatformLayout, AuthLayout
router/       rutas y guardas (auth, permisos, SUPERADMIN)
services/     cliente HTTP (axios + CSRF de Sanctum)
stores/       Pinia: auth, ui, toasts, confirm
types/        modelos compartidos
views/        app/ (cliente), platform/ (SUPERADMIN), auth/, legal/
```
