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

## Redes disponibles

El catálogo (`SocialProviderSeeder`) sólo contiene redes **con adaptador
implementado**: `fake` (pruebas, deshabilitado en producción), `facebook` e
`instagram`. Una red nueva se añade implementando `SocialProviderInterface`,
registrándola en `SocialProviderManager` y añadiéndola al seeder. El seeder usa
`firstOrCreate`: re-ejecutarlo no deshabilita lo que SUPERADMIN ya habilitó.

## Adaptadores de Meta (Facebook e Instagram) — implementados

Ambos extienden `Providers/Meta/AbstractMetaProvider` (OAuth común de Facebook
Login) y usan `Providers/Meta/MetaGraph` (cliente Graph API):

- **Versión de Graph API configurable**: `META_GRAPH_VERSION` (por defecto
  `v25.0`) o, con prioridad, el ajuste del proveedor en SUPERADMIN →
  Integraciones sociales → Ajustes avanzados. Meta retira cada versión ~2 años
  después de publicarla (la v21.0 caduca el 21-ene-2027).
- **Tokens**: tras el código se canjea un token de usuario de **larga duración**
  (`fb_exchange_token`); con él, `/me/accounts` devuelve **page tokens que no
  caducan**, que se guardan **cifrados por destino**
  (`social_connection_destinations.access_token`, cast `encrypted`, oculto). Los
  adaptadores prefieren el token del destino (`OAuthTokens::destinationToken`).
- **Caducidad/revocación**: si Graph responde error 190 (token inválido), el
  adaptador lanza `SocialTokenExpiredException`; la conexión pasa a **Expirada**
  (evento `expired` + auditoría `social.token_expired`) y la UI muestra
  **Reconectar**. El comando `social:refresh-tokens` (cada hora) renueva los tokens
  con refresh token que caducan en <24 h y marca expirados los que ya no se pueden
  renovar.
- **Reconexión sin duplicados**: el OAuth guarda `external_account_id` (id de la
  cuenta de Meta) y, si la cuenta ya estaba conectada a la marca, **actualiza** la
  conexión (tokens, destinos; los destinos que ya no llegan se desactivan).
- **Errores legibles**: `SocialProviderException` (HTTP 502) con el mensaje de
  Meta; nunca incluye tokens.
- **Métricas vigentes**: Meta retiró `page_impressions*`/`post_impressions*` (nov-2025)
  e `impressions` de Instagram (abr-2025). Se usan `views`/`reach`; si alguna
  métrica no está disponible se consulta el resto por separado (queda en 0).

### Facebook (Páginas)
- **Publicar**: texto (con enlace → vista previa vía `link`), una imagen
  (`/{page}/photos`), **varias imágenes** en una sola publicación (fotos sin
  publicar + `attached_media`) y **video** (`/{page}/videos`, `file_url`).
- **Métricas de cuenta**: `followers_count` + Insights `page_media_view`,
  `page_total_media_view_unique`, `page_post_engagements`.
- **Métricas de post**: reacciones/comentarios/compartidos + `post_media_view`,
  `post_total_media_view_unique`, `post_clicks`.
- **Inbox**: comentarios del feed (omite los de la propia página) y respuesta con
  `/{comment}/comments`.
- Scopes por defecto: `public_profile`, `pages_show_list`, `pages_read_engagement`,
  `pages_read_user_content`, `pages_manage_posts`, `pages_manage_engagement`,
  `read_insights` (editables en SUPERADMIN).

### Instagram (cuenta profesional vinculada a una Página)
- **Misma app de Meta**: si Instagram no tiene credenciales propias usa las de
  Facebook (`SocialProviderManager::credentials`).
- **Destinos**: `/me/accounts{instagram_business_account}` → una por cuenta
  profesional (`@usuario`), con el page token de su Página.
- **Publicar en dos pasos** (contenedor → `media_publish`): imagen, **reel**
  (`media_type=REELS`, espera a `status_code=FINISHED`) y **carrusel** de 2–10
  elementos (imágenes y/o videos). Instagram **no admite sólo texto**
  (`Capability::TEXT=false`) y descarga los archivos desde una URL pública: se usa
  la URL firmada temporal del archivo (120 min).
- **Métricas**: `followers_count`/`media_count` + Insights `reach`, `views`,
  `total_interactions` (`metric_type=total_value`); por publicación `like_count`,
  `comments_count` + `views`, `reach`, `shares`.
- **Inbox**: comentarios de las publicaciones y respuesta con `/{comment}/replies`.
- Scopes: `instagram_basic`, `instagram_content_publish`,
  `instagram_manage_comments`, `instagram_manage_insights`, `pages_show_list`,
  `pages_read_engagement`.

### Validación antes de publicar
`PublicationPlanner` valida al **programar** y al **publicar ahora**: la red debe
tener cuentas conectadas y activas en la marca, y recibir lo que exige (Instagram:
al menos una imagen o video; sin video en redes que no lo admiten). Errores → 422.

### SUPERADMIN
Integraciones sociales permite habilitar cada red, guardar App ID/Secret
(cifrados), **Probar conexión** (`POST /platform/social-providers/{p}/test`, emite
un app token), ajustar versión de Graph y scopes, y copiar las URLs que pide Meta
(redirect OAuth, callback de borrado de datos, privacidad y términos).

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
