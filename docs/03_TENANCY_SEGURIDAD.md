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

## Inicio de sesión único (SAML 2.0)
Módulo `Sso` (implementación y endpoints en docs/05, «SSO — Implementación»). Cada
organización con `feature.sso` (Enterprise) conecta su proveedor de identidad (IdP:
Microsoft Entra ID, Okta, Google Workspace, ADFS…). Decisiones de seguridad:

- **Dominios verificados**: sólo entra por SSO quien tenga un correo de un dominio que la
  organización demostró suyo con un registro TXT (`loop7-verification=…`). Un dominio
  verificado tiene una sola dueña, garantizado por un índice único (`verified_domain`).
  No se pueden reclamar dominios de correo gratuito (gmail.com, outlook.com…). La respuesta
  del IdP debe traer un correo de un dominio verificado **de esa organización**: el IdP de
  un cliente no puede iniciar sesión como alguien de otro dominio.
- **Sólo iniciado por Loop7** (SP-initiated): `POST /sso/discover` crea la petición SAML y
  un `RelayState` aleatorio de un solo uso (10 min, en caché, guarda el ID de la petición);
  el ACS exige ese `RelayState` y que `InResponseTo` coincida. Se rechazan las respuestas
  iniciadas por el IdP o no solicitadas.
- **Validación estricta** (onelogin/php-saml, MIT): aserción firmada con el certificado
  configurado (RSA-SHA256, sin algoritmos obsoletos; se admiten hasta 3 certificados para
  rotarlos), emisor, audiencia (Entity ID de la organización), destino y destinatario
  exactos (la URL canónica del ACS sale de `APP_URL`, no de cabeceras `Host`), tiempos,
  esquema XML y sin DOCTYPE (XXE). Cada aserción se acepta una vez mientras está vigente
  (anti-repetición en caché).
- **Sin sesión en el ACS**: el POST entre sitios del IdP no lleva cookies; el ACS emite un
  código de 64 caracteres (60 s, un solo uso) en el fragmento de la URL del SPA
  (`/sso/callback#code=…`, no viaja al servidor ni en el Referer) y el SPA lo canjea en
  `POST /sso/exchange` (con CSRF) presentando además el **verificador** cuyo SHA-256 envió
  al empezar (como PKCE, guardado en `sessionStorage`): nadie puede terminar en tu navegador
  un inicio de sesión que empezó otra persona (login CSRF).
- **Cuentas**: entra un miembro activo de la organización. Con «alta automática» (JIT) se
  crea la cuenta **sólo si el correo aún no existe** (con el rol por defecto, nunca OWNER, y
  respetando `team_members.max`): una cuenta que ya existe —p. ej. miembro de otra
  organización— necesita invitación; el IdP nunca se apropia de ella. Las cuentas de
  SUPERADMIN, bloqueadas o eliminadas no entran por SSO.
- **SSO obligatorio**: los miembros activos con correo de un dominio verificado ya no entran
  con contraseña (`403 sso_required`). La persona propietaria conserva la contraseña como
  acceso de emergencia (si el IdP falla puede entrar a desactivarlo); los SUPERADMIN y quien
  no es miembro de esa organización no se ven afectados. Activarlo no cierra las sesiones
  abiertas ni revoca las API keys existentes.
- Eliminar la organización borra su conexión y libera sus dominios verificados.
- **MFA**: al entrar por SSO no se pide el 2FA local; lo aplica el IdP (recomendar a los
  clientes exigir MFA allí).
- **Errores**: el SPA sólo recibe un código genérico (`/login?sso_error=not_member`…); el
  detalle técnico queda en la auditoría (`sso.login_failed`) y en la «Prueba de conexión»
  de quien administra (que valida la respuesta sin iniciar sesión).
- **Fuera de alcance por ahora**: cierre de sesión único (SLO), aprovisionamiento SCIM
  (las bajas se hacen en Loop7: quitar o suspender al miembro), peticiones firmadas y
  aserciones cifradas. La sesión dura lo que la de Laravel.
- Configurarlo exige `organization.update` y queda bloqueado durante una impersonación.
  Límites: discover y exchange 10/min, ACS 30/min, verificación de dominios 10/min.
- Auditoría: `sso.connection_updated`, `sso.domain_added|verified|removed`, `sso.login`,
  `sso.login_failed`, `sso.user_provisioned`, `sso.tested`, `sso.password_login_blocked`.
- Tests: `tests/Feature/Sso/SsoTest.php` (respuestas firmadas de verdad con
  `tests/Support/SamlIdp.php`) y E2E `e2e/sso.spec.ts`.

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
- gestión y revocación de sesiones: Sanctum guarda el hash de la contraseña en la sesión
  (`AuthenticateSession`), así que cambiar o restablecer la contraseña cierra las demás
  sesiones; «Cerrar las demás sesiones» en Mi perfil
  (`POST /me/sessions/logout-others`, con contraseña, 6/min, auditado) lo hace sin
  cambiarla. Bloqueado durante una impersonación.

## Uploads
- validar MIME real y extensión;
- límites por plan;
- nombres aleatorios;
- evitar ejecución;
- antivirus/scan opcional en fase avanzada;
- archivos privados con URLs firmadas.

## Auditoría mínima
Login, logout, fallos relevantes, 2FA, password, sesiones, invitaciones, cambios de rol, permisos, conexión/desconexión social, publicaciones, cambios de billing, cambios de gateways, cambios de IA, impersonación, exportaciones y borrados.
