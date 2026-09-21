# Roadmap de implementación

## Fase 0 — Fundación
Repositorio, Docker local, Laravel/Vue, CI, estándares, configuración, health checks.

## Fase 1 — Identity + Tenancy + Layout
Auth, verificación email, MFA base, Organizations, SUPERADMIN, RBAC, Brand Access, admin layout sidebar, auditoría, tests de aislamiento.

## Fase 2 — Billing SaaS
Planes, entitlements, trial, Stripe/Mercado Pago/Openpay por adaptadores, webhooks, SUPERADMIN de gateways, usage limits.

## Fase 3 — Brands + Brand Brain + Media
Brands, identidad, media library, productos/servicios, knowledge base inicial.

## Fase 4 — Social Connections
OAuth framework genérico + Meta primero; después LinkedIn/TikTok/X y demás. Health/reauth/token lifecycle.

## Fase 5 — Content Studio + Calendar
Composer, variantes multired, previews, campañas, calendario, aprobaciones y permisos.

## Fase 6 — Publishing Engine
Scheduler, Redis, Horizon, jobs, retries, rate limit, estados, dashboard de fallos.

## Fase 7 — IA
Providers texto/imagen, créditos, BYOK, generación y adaptación por red.

## Fase 8 — Analytics
Snapshots, sync jobs, dashboards, reportes y exportación.

## Fase 9 — Inbox
Integraciones permitidas, asignación, respuesta, etiquetas y asistencia IA.

## Fase 10 — Automations
Triggers/conditions/actions, RSS/webhooks/eventos internos, builder posterior.

## Fase 11 — API pública + MCP
API keys scoped, rate limits, docs, webhooks salientes y MCP.

## Fase 12 — Hardening
Pentest, load tests, disaster recovery, backups restore-tested, observabilidad y revisión de compliance.
