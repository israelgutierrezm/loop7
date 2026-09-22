# Runbook de operación y hardening (Fase 12)

Guía operativa para preproducción/producción. Complementa el checklist de
[19_CHECKLIST_SEGURIDAD_PREPROD.md](19_CHECKLIST_SEGURIDAD_PREPROD.md) y la
observabilidad de [13_OBSERVABILIDAD_CALIDAD.md](13_OBSERVABILIDAD_CALIDAD.md).

## Cabeceras de seguridad (implementado)
`App\Http\Middleware\SecurityHeaders` (global) añade a toda respuesta:
`X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
`Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` mínima y
`Content-Security-Policy` (config `config/security.php`, override con `SECURITY_CSP`).
`Strict-Transport-Security` sólo se envía sobre HTTPS (`SECURITY_HSTS=true`).
El SPA define su propia CSP en su hosting estático.

## CORS (implementado)
`config/cors.php` con orígenes por env `CORS_ALLOWED_ORIGINS` (coma-separados;
fallback `FRONTEND_URL`). `supports_credentials=true` (Sanctum) ⇒ nunca `*`.

## Correlation ID (implementado)
`App\Http\Middleware\RequestId` asigna `X-Request-Id` por request (acepta uno
entrante con formato seguro), lo añade al contexto de logs y a la respuesta.

## Proxy / HTTPS (detrás de balanceador o CDN)
- `TRUSTED_PROXIES` (`*` o lista de IP/CIDR): necesario para que la app detecte
  HTTPS por `X-Forwarded-Proto` y, con ello, envíe HSTS, marque cookies `Secure` y
  genere URLs `https`. Se lee en `bootstrap/app.php` (funciona con variables de
  entorno reales aunque se use `config:cache`).
- En producción (`APP_ENV=production`) el esquema de URLs se fuerza a `https`
  (`AppServiceProvider`), de modo que los `redirect_uri` de OAuth y las URLs
  firmadas de media sean correctos.

## Variables de entorno mínimas en producción
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.tudominio.com
FRONTEND_URL=https://app.tudominio.com
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SESSION_DOMAIN=.tudominio.com
SANCTUM_STATEFUL_DOMAINS=app.tudominio.com
CORS_ALLOWED_ORIGINS=https://app.tudominio.com
SECURITY_HSTS=true
TRUSTED_PROXIES=*          # o la lista de IP/CIDR de tus balanceadores
QUEUE_CONNECTION=redis
CACHE_STORE=redis
FILESYSTEM_DISK=s3
LOG_CHANNEL=stack          # canal JSON hacia stdout/stderr para logs estructurados
LOG_LEVEL=warning
```

## Backups y prueba de restore
- **Backups automáticos:** dump diario de MySQL + almacenamiento (S3-compatible),
  retención 30 días. Ejemplo cron:
  ```bash
  mysqldump --single-transaction --routines --triggers social_saas \
    | gzip > /backups/social_saas_$(date +%F).sql.gz
  ```
- **Prueba de restore (trimestral, obligatoria):**
  ```bash
  gunzip < /backups/social_saas_YYYY-MM-DD.sql.gz | mysql social_saas_restore_test
  php artisan migrate:status --database=restore_test   # verificar integridad
  ```
- Verificar que los cast `encrypted` siguen descifrando con la `APP_KEY` vigente.

## Rotación de secretos
- `APP_KEY`: rotar implica re-cifrar columnas `encrypted` (tokens OAuth, credenciales
  de gateways/IA, BYOK, MFA). Procedimiento: descifrar con clave antigua y re-cifrar
  con la nueva en una migración de datos controlada; nunca rotar sin este paso.
- Credenciales de proveedor (SUPERADMIN): actualizar en el panel; quedan cifradas.
- **API keys:** revocar desde `/app/api-keys` (deja de funcionar de inmediato) y
  emitir una nueva; el secreto sólo se muestra una vez.

## Auditoría de dependencias
En CI (`.github/workflows/ci.yml`) y manual:
```bash
composer audit --no-dev
npm audit --omit=dev --audit-level=high
```

## Respuesta a incidentes (básico)
1. Contener: revocar API keys/credenciales comprometidas; suspender la Organization
   afectada desde SUPERADMIN si procede.
2. Evaluar alcance con `audit_logs` (acciones críticas, correlation id) — sin secretos.
3. Rotar secretos afectados (ver arriba).
4. Comunicar según obligaciones legales/contractuales.
5. Post-mortem y acciones correctivas.

## Colas y jobs en producción
Redis + Horizon (`php artisan horizon`, protegido tras auth/SUPERADMIN) y
`php artisan schedule:run` por cron. Trabajos fallidos operables desde `/platform/jobs`.

## Notas de cumplimiento del checklist
- Tokens OAuth, credenciales e IA (BYOK) cifrados at-rest (`encrypted`/`encrypted:array`).
- API keys: sólo hash SHA-256 en BD.
- Logs sin secretos: `AuditLogger` redacta claves sensibles (password, token, secret,
  access_token, api_key, etc.).
- Aislamiento de tenant e IDOR cubiertos por tests en cada módulo.
- MFA/TOTP disponible; recomendado obligatorio para SUPERADMIN antes de producción.
