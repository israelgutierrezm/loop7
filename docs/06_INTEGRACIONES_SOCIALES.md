# Integraciones sociales

## Objetivo
Soportar mediante adaptadores la mayor cantidad de redes posible sin acoplar el dominio a una implementación concreta.

## Prioridad
### Fase alta
- Facebook Pages
- Instagram Professional
- Threads
- LinkedIn Profile/Organization según permisos oficiales
- TikTok
- X

### Fase media
- YouTube / Shorts
- Pinterest
- Google Business Profile
- Bluesky
- Mastodon

## Contrato
`SocialProviderInterface` debe exponer capacidades conceptuales, no asumir que todas las redes soportan todo:
- authorizeUrl()
- exchangeCode()
- refreshToken()
- listDestinations()
- capabilities()
- publish()
- deleteRemotePost() cuando exista
- fetchPostMetrics()
- fetchAccountMetrics()
- fetchInbox() cuando exista

## Capability Matrix
Mantener una tabla/configuración por provider y versión:
- text
- image
- multi_image
- video
- short_video
- story
- carousel
- poll
- document
- link
- schedule_native
- comments_read
- comments_reply
- analytics_post
- analytics_account

El frontend debe habilitar/deshabilitar formatos según capabilities reales.

## Regla
Antes de implementar una integración, revisar documentación oficial vigente, permisos, revisión/auditoría de app, límites, quotas, políticas de almacenamiento y restricciones de contenido.

---

## Cumplimiento para Meta App Review

Meta exige, además de la app (App ID/Secret) y el OAuth redirect HTTPS, tres cosas
que el App Review revisa. Ya están construidas del lado del código:

- **Política de Privacidad (URL pública):** `/privacidad` (SPA, sin login).
- **Términos de servicio (URL pública):** `/terminos`.
- **Borrado de datos:** `/eliminar-datos` (instrucciones + consulta de estado) y el
  **callback firmado** `POST /api/v1/data-deletion/facebook`, que:
  - verifica el `signed_request` con HMAC-SHA256 y el App Secret del proveedor;
  - registra la solicitud (`data_deletion_requests`) y lanza `PurgeExternalUserData`
    (borra conexiones cuya cuenta pertenece al usuario y sus conversaciones de inbox);
  - responde con `{ url, confirmation_code }` (formato requerido por Meta).
  - Está exento de CSRF (llamada servidor-a-servidor).

Plantillas legales: `PrivacyView`/`TermsView` traen placeholders `[NOMBRE DE LA
EMPRESA]`, `[CORREO DE CONTACTO]`, `[PAÍS/JURISDICCIÓN]` — completar y revisar con
asesoría legal antes de enviar a revisión.

**Fuera del código (trámite del titular ante Meta):** verificación de negocio,
screencast y justificación de cada permiso, y poner la app en modo *Live*. El
almacenamiento seguro de tokens (cifrado at-rest) ya se cumple.

## Conexión manual por token

Además del flujo OAuth, existe una **conexión manual** para capturar un token a
mano (p. ej. un System User token o uno de pruebas del Graph API Explorer), útil
para conectar/probar antes de completar la revisión de Meta:

- `POST /api/v1/brands/{brand}/social/connections/{provider}/manual` con
  `external_account_name`, `access_token` (obligatorios), `external_account_id`,
  `refresh_token`, `token_expires_at` y `destinations[]` opcionales. Permiso
  `social_accounts.connect`; el proveedor debe estar habilitado.
- El token se guarda **cifrado** (igual que en OAuth). Es una función interna que
  **no afecta** al App Review (no usa el Login de Meta).
- Frontend: botón "Conexión manual" en *Redes sociales* (`/app/social`), diálogo
  `ManualConnectionDialog`.
