# Tenancy y seguridad

## Límite tenant
`Organization` es el boundary principal. Los datos operativos del cliente deben incluir `organization_id` o quedar relacionados de forma inequívoca con una entidad que lo incluya.

## Aislamiento
- Policies obligatorias.
- Route model binding scoped cuando aplique.
- Queries siempre scoped.
- Tests explícitos de acceso cruzado Organization A -> recurso Organization B = 404/403.
- Evitar identificadores secuenciales expuestos cuando aporte seguridad adicional; usar UUID/ULID públicos.

## OAuth social
- Nunca solicitar contraseña social.
- Authorization Code Flow y PKCE cuando el proveedor lo soporte.
- `state` criptográficamente seguro y de un solo uso.
- Redirect URIs allowlisted.
- scopes mínimos.
- tokens cifrados at-rest.
- refresh seguro.
- registro de expiración/estado.
- reconexión explícita cuando sea necesaria.

## Secretos
- `.env` solo para desarrollo local.
- Producción: Secret Manager/KMS cuando infraestructura lo permita.
- Datos sensibles en DB usando cifrado de aplicación.
- No mostrar secretos completos después de guardarlos.
- No incluir secretos en logs, exceptions, Telescope o Sentry.

## Web
- Cookies Secure, HttpOnly, SameSite apropiado.
- CSRF con Sanctum SPA.
- CSP restrictiva.
- HSTS en producción.
- clickjacking protection.
- rate limiting.
- bloqueo progresivo ante abuso.
- MFA/TOTP.
- verificación de correo.
- gestión y revocación de sesiones.

## Uploads
- validar MIME real y extensión;
- límites por plan;
- nombres aleatorios;
- evitar ejecución;
- antivirus/scan opcional en fase avanzada;
- archivos privados con URLs firmadas.

## Auditoría mínima
Login, logout, fallos relevantes, 2FA, password, sesiones, invitaciones, cambios de rol, permisos, conexión/desconexión social, publicaciones, cambios de billing, cambios de gateways, cambios de IA, impersonación, exportaciones y borrados.
