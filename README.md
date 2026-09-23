# Loop7 — gestión de redes sociales con IA

SaaS multi-organización para crear, aprobar, programar, publicar y analizar
contenido en redes sociales, con asistente de IA que usa el contexto de cada
marca (*Brand Brain*), inbox unificado, automatizaciones y API pública.

- **Backend:** Laravel 12 (PHP 8.3), monolito modular (`apps/backend/app/Modules/*`),
  Sanctum (SPA por cookies), spatie/permission por organización, MySQL 8, Redis + Horizon.
- **Frontend:** Vue 3 + TypeScript + Vite + Tailwind CSS 4 + Pinia (`apps/frontend`).
- **Despliegue:** imagen Docker única (Nginx + PHP-FPM, SPA y API en el mismo
  origen) publicada en GHCR; `docker-compose.yml` levanta app, worker, scheduler,
  MySQL y Redis.

## Qué incluye

| Área | Módulos |
|------|---------|
| Clientes | Organizaciones, equipo y roles (9 roles, permisos granulares), marcas con Brand Brain, biblioteca de medios |
| Contenido | Editor con variantes por red, flujo de aprobación, calendario, campañas, publicación por colas idempotente |
| Redes | Facebook (Páginas) e Instagram (cuentas profesionales) vía Graph API; conexión OAuth o manual por token |
| IA | OpenAI y Anthropic (texto), OpenAI (imagen); créditos por plan y claves propias (BYOK) |
| Operación | Analítica, inbox con respuestas sugeridas, automatizaciones por eventos, API pública + servidor MCP |
| Plataforma | Panel SUPERADMIN: organizaciones, usuarios, planes, suscripciones y pagos, pasarelas (Stripe, Mercado Pago, Openpay, manual), proveedores de IA y redes, colas, auditoría, configuración |

## Estructura

```
apps/backend     API Laravel (módulos en app/Modules, pruebas en tests/)
apps/frontend    SPA Vue
docker/          configuración de la imagen (nginx, php, supervisor, entrypoint)
docs/            especificación, decisiones, guías de operación
Dockerfile       imagen de producción
docker-compose.yml  stack completo (app, worker, scheduler, MySQL, Redis)
```

## Desarrollo local

Guía completa en [docs/00_DESARROLLO_LOCAL.md](docs/00_DESARROLLO_LOCAL.md). Resumen:

```bash
cd apps/backend && composer install && cp .env.example .env && php artisan key:generate
php artisan migrate --seed && php artisan serve --port=8000
cd apps/frontend && npm install && npm run dev   # http://localhost:5173
```

Calidad: `php artisan test`, `vendor/bin/pint`, `vendor/bin/phpstan analyse`
(backend) y `npm run type-check`, `npm run build` (frontend).

## Documentación

- Arquitectura y seguridad: [docs/02](docs/02_ARQUITECTURA.md), [docs/03](docs/03_TENANCY_SEGURIDAD.md), [docs/04](docs/04_ROLES_PERMISOS.md)
- Integraciones sociales y Meta App Review: [docs/06](docs/06_INTEGRACIONES_SOCIALES.md)
- Billing y pasarelas: [docs/08](docs/08_BILLING_PAGOS.md) · SUPERADMIN: [docs/09](docs/09_SUPERADMIN.md)
- API pública y MCP: [docs/11](docs/11_API_CONVENCIONES.md)
- Seguridad pre-producción y operación: [docs/19](docs/19_CHECKLIST_SEGURIDAD_PREPROD.md), [docs/21](docs/21_RUNBOOK_OPERACION.md)

Las instrucciones permanentes para el desarrollo asistido están en [CLAUDE.md](CLAUDE.md).
