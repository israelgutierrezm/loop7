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
- [x] ✅ errores de red de Meta sin tokens — Graph recibe el token en la query y el mensaje de cURL incluye la URL: `MetaGraph` lo sustituye por un mensaje propio y `SecretRedactor` limpia errores de publicación y trabajos fallidos (`FacebookProviderTest`); lo mismo en Threads, LinkedIn, X, YouTube y TikTok (`AbstractOAuth2Provider::send`)
- [x] ✅ refresh tokens de un solo uso (X) sin carreras — renovación con bloqueo por conexión y relectura (`NewProvidersIntegrationTest`)
- [x] ✅ webhooks firmados/verificados — Stripe (HMAC), Mercado Pago (x-signature + consulta a la API), Openpay (Basic auth)
- [x] ✅ webhooks salientes sin SSRF — `OutboundUrl` (sólo IPs públicas, IP fijada, sin redirecciones); los webhooks de «API y accesos» además sólo https
- [x] ✅ webhooks salientes firmados — Standard Webhooks (HMAC-SHA256 con `webhook-id` y marca de tiempo), secreto cifrado y mostrado una vez, rotación con doble firma 24 h (`WebhooksTest`)
- [x] ✅ webhooks entrantes de automatizaciones — URL con token de 48 caracteres (cifrado; búsqueda por hash), límite por URL, 64 KB, idempotencia con `Idempotency-Key` (`InboundTriggersTest`)
- [x] ✅ callbacks de Meta por app (Facebook/Instagram y Threads): borrado de datos y desautorización con `signed_request` verificado; purga acotada a las redes de cada app (`ProviderComplianceTest`)
- [x] ✅ revocación del acceso en la red al desconectar (YouTube, X, TikTok) sin afectar a otras conexiones de la misma cuenta
- [x] ✅ webhooks de TikTok firmados (HMAC con el client secret) e idempotentes
- [x] ✅ cupos por red en el plan (`x_posts.month`, `youtube_uploads.day`): un cliente no agota el saldo de X ni las subidas diarias de YouTube de la plataforma (`ProviderQuotaTest`)
- [x] ✅ feeds RSS sin SSRF ni XXE — cada redirección revalidada, 2 MB, 10 s, XML sin entidades ni DTD externas (`InboundTriggersTest`)
- [x] ✅ idempotencia — publicación (consolidación idempotente), webhooks de pago, créditos IA
- [x] ✅ tests IDOR/tenant isolation — en cada módulo
- [x] ✅ Brand Access — recursos hijos verifican acceso a su marca (`BrandAccessTest`)
- [x] ✅ dependency audit Composer/NPM — pasos en CI
- [x] ✅ SAST/linters en CI — Pint + PHPStan (nivel 5) + tests
- [x] ✅ upload validation — MIME real/tamaño/límite de plan (MediaLibrary; logos de marca blanca sin SVG)
- [x] ✅ documentos del Brand Brain — tipo real vs extensión, 10 MB, sin imágenes del PDF ni zip bombs de Word, archivo privado; los fragmentos se dan a la IA como datos y se le indica no seguir instrucciones que contengan
- [x] ✅ exportaciones CSV sin inyección de fórmulas — `CsvWriter` prefija con apóstrofo lo que empieza por `= + - @` (y añade BOM UTF-8)
- [x] ✅ URLs firmadas para privados — MediaService y logos de marca blanca (URL temporal)
- [ ] ⚙️ SUPERADMIN con MFA obligatorio (recomendado activar antes de prod)
- [x] ✅ impersonación auditada, con caducidad (60 min), acciones críticas bloqueadas e `impersonated_by` en la auditoría
- [x] ✅ bloqueo de cuentas por SUPERADMIN: rechaza el login y corta la sesión abierta (`EnsureAccountActive`, `PlatformUsersTest`)
- [ ] ⚙️ secret rotation procedure — documentado (runbook)
- [ ] ⚙️ incident response básico — documentado (runbook)
- [x] ✅ revisión permisos OAuth mínimos — `defaultScopes` por proveedor
- [ ] ⚙️ revisión de políticas oficiales de cada proveedor (antes de habilitar cada red)
- [ ] ⚙️ revisiones de las apps de cada red antes de abrirlas a clientes (docs/06): Meta App Review (Facebook, Instagram y la app propia de Threads), LinkedIn Community Management API (páginas), verificación OAuth de Google + auditoría de YouTube API Services (sin ella los videos quedan privados y hay 100 subidas/día), auditoría de TikTok Content Posting (sin ella todo queda «Solo yo»), y saldo de pago por uso en X

## Correlation ID / observabilidad
- [x] ✅ `X-Request-Id` por request en contexto de logs y respuesta (`RequestId`).
- Métricas y alertas: ver [13_OBSERVABILIDAD_CALIDAD.md](13_OBSERVABILIDAD_CALIDAD.md).
