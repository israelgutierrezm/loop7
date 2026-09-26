# Tenancy y seguridad

## Límite tenant
`Organization` es el boundary principal. Los datos operativos del cliente deben incluir `organization_id` o quedar relacionados de forma inequívoca con una entidad que lo incluya.

## Aislamiento
- Policies obligatorias.
- Route model binding scoped cuando aplique.
- Queries siempre scoped.
- Tests explícitos de acceso cruzado Organization A -> recurso Organization B = 404/403.
- Evitar identificadores secuenciales expuestos cuando aporte seguridad adicional; usar UUID/ULID públicos.

## Brand Access (dentro de la Organization)
Un miembro accede a todas las Brands (`all_brands_access`) o sólo a las asignadas
(`brand_user_access`). La regla vive en un único servicio, `BrandAccess`, que usan la
`BrandPolicy`, el middleware de tenant, el contexto del SPA y las consultas agregadas.

- Rutas por Brand: `ResolvesBrand::resolveBrand()` (policy `view`).
- Recursos hijos resueltos por `public_id` (contenido, variantes, flujo de aprobación,
  campañas, conversaciones del inbox, automatizaciones de una marca):
  `ResolvesBrand::authorizeBrand($recurso->brand)`. Sin esto, un miembro limitado a una
  marca podía operar recursos de otra conociendo su id.
- Listados y agregados (automatizaciones, dashboard): `BrandAccess::restrictedBrandIds()`.
- Destinatarios de avisos y personas asignables: `MembershipService::membersWithPermission()`
  (permiso + membresía activa + acceso a la Brand).
- Tests: `tests/Feature/Tenancy/BrandAccessTest.php`.

## Salidas hacia URLs de clientes (anti-SSRF)
Cualquier URL que configure un cliente y que el servidor vaya a llamar (webhooks de
automatizaciones) pasa por `App\Support\Security\OutboundUrl`: sólo http(s), sin
credenciales embebidas, sin hosts internos y resolviendo a IPs públicas (se rechazan
privadas, reservadas, loopback, link-local/metadatos y CGNAT). Se valida al guardar y al
ejecutar, la conexión se fija a la IP validada (evita DNS rebinding) y no se siguen
redirecciones.

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
- verificación de correo: el enlace firmado (60 min) vuelve siempre al SPA, también si
  caducó (`/verificar-correo?status=invalid`, con botón para pedir otro). Mientras no se
  verifica, el panel muestra un aviso con «Enviar enlace» y Mi perfil indica el estado
  (`POST /email/verification-notification`, 6/min).
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
