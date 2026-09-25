# Checklist de seguridad antes de producción

Estado: ✅ implementado en código · ⚙️ configuración/operación en el despliegue.
Ver runbook: [21_RUNBOOK_OPERACION.md](21_RUNBOOK_OPERACION.md).

- [ ] ⚙️ APP_DEBUG=false (env de producción)
- [ ] ⚙️ HTTPS obligatorio (terminación TLS en el proxy)
- [x] ✅ HSTS — `SecurityHeaders` sobre HTTPS (`SECURITY_HSTS`)
- [x] ✅ CSP — `config/security.php` (override `SECURITY_CSP`); el SPA define la suya
- [ ] ⚙️ cookies Secure/HttpOnly/SameSite — `SESSION_SECURE_COOKIE=true` (HttpOnly/SameSite ya por defecto)
- [x] ✅ CORS restrictivo — `config/cors.php` por `CORS_ALLOWED_ORIGINS` (sin `*`)
- [x] ✅ MFA disponible (TOTP)
- [x] ✅ rate limits — `api`, `auth`, `public-api`, `social-publish`
- [x] ✅ brute-force protection — limitador `auth` (10/min por IP)
- [x] ✅ email verification
- [ ] ⚙️ backups automáticos (cron + almacenamiento externo; ver runbook)
- [ ] ⚙️ prueba de restore (trimestral; ver runbook)
- [x] ✅ tokens OAuth cifrados (`encrypted` at-rest)
- [x] ✅ API keys cifradas — sólo hash SHA-256 en BD
- [x] ✅ logs sin secretos — `AuditLogger` redacta claves sensibles (test)
- [x] ✅ webhooks firmados/verificados — Stripe (HMAC), Mercado Pago (x-signature + consulta a la API), Openpay (Basic auth)
- [x] ✅ webhooks salientes sin SSRF — `OutboundUrl` (sólo IPs públicas, IP fijada, sin redirecciones)
- [x] ✅ idempotencia — publicación (consolidación idempotente), webhooks de pago, créditos IA
- [x] ✅ tests IDOR/tenant isolation — en cada módulo
- [x] ✅ Brand Access — recursos hijos verifican acceso a su marca (`BrandAccessTest`)
- [x] ✅ dependency audit Composer/NPM — pasos en CI
- [x] ✅ SAST/linters en CI — Pint + PHPStan (nivel 5) + tests
- [x] ✅ upload validation — MIME real/tamaño/límite de plan (MediaLibrary; logos de marca blanca sin SVG)
- [x] ✅ URLs firmadas para privados — MediaService y logos de marca blanca (URL temporal)
- [ ] ⚙️ SUPERADMIN con MFA obligatorio (recomendado activar antes de prod)
- [x] ✅ impersonación auditada, con caducidad (60 min), acciones críticas bloqueadas e `impersonated_by` en la auditoría
- [ ] ⚙️ secret rotation procedure — documentado (runbook)
- [ ] ⚙️ incident response básico — documentado (runbook)
- [x] ✅ revisión permisos OAuth mínimos — `defaultScopes` por proveedor
- [ ] ⚙️ revisión de políticas oficiales de cada proveedor (antes de habilitar cada red)

## Correlation ID / observabilidad
- [x] ✅ `X-Request-Id` por request en contexto de logs y respuesta (`RequestId`).
- Métricas y alertas: ver [13_OBSERVABILIDAD_CALIDAD.md](13_OBSERVABILIDAD_CALIDAD.md).
