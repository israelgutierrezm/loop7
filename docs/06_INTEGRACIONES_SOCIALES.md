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
implementado**: `fake` (pruebas, deshabilitado en producción), `facebook`,
`instagram`, `threads`, `linkedin`, `x`, `youtube` y `tiktok` (las nuevas llegan
**deshabilitadas** hasta que SUPERADMIN configura su app). Una red nueva se añade
implementando `SocialProviderInterface`, registrándola en `SocialProviderManager` y
añadiéndola al seeder. El seeder usa `firstOrCreate`: re-ejecutarlo no deshabilita lo
que SUPERADMIN ya habilitó (al desplegar, re-ejecutarlo añade las redes nuevas).

Todas se revisaron contra la documentación oficial vigente (sep-2026) antes de
implementarlas, como exige la regla de arriba. Resumen:

| Red | Publica | Métricas | Inbox | Revisión de la app / límites clave |
|---|---|---|---|---|
| Facebook | texto, enlace, 1–N imágenes, video, historia | página y publicación | comentarios | Meta App Review |
| Instagram | imagen, reel, carrusel ≤ 10, historia | cuenta y publicación | comentarios | Meta App Review; 100 publicaciones por API/24 h |
| Threads | texto ≤ 500, imagen, video, carrusel 2–20 | cuenta y publicación | respuestas | App Review; app propia de Threads; 250 publicaciones/24 h |
| LinkedIn | texto ≤ 3000, 1–20 imágenes, video | páginas (y perfil con CM API) | comentarios de páginas | Perfil: alta directa. Páginas: Community Management API |
| X | texto 280 ponderado, ≤ 4 imágenes o 1 video/GIF | publicación y seguidores | menciones | Pago por uso: cada publicación y archivo se cobran |
| YouTube | 1 video (Short si vertical ≤ 3 min) | canal y video | comentarios | Verificación OAuth + auditoría de YouTube; 100 subidas/día por proyecto |
| TikTok | 1 video | cuenta y videos públicos | — (sin API) | Auditoría de TikTok; sin ella todo queda «Solo yo» |

### Piezas comunes
- **`Providers/OAuth2/AbstractOAuth2Provider`**: Authorization Code (+ PKCE salvo que
  el proveedor no lo admita en web), canje y renovación de tokens, errores HTTP
  (401 → `SocialTokenExpiredException`; fallos de red sin el detalle de cURL, que
  puede llevar credenciales) y «Probar conexión» sin cuenta conectada: canjea un
  código inventado y distingue «cliente inválido» (credenciales mal) de «código
  inválido» (credenciales bien).
- **Tokens frescos**: publicar, métricas e inbox usan
  `SocialConnectionService::freshTokens()`, que renueva el token si caduca en menos
  de 10 min (redes con tokens de 1–24 h). La renovación va con **bloqueo por
  conexión** y relee el token antes de usarlo: X rota el refresh token y dos
  renovaciones simultáneas con el mismo invalidarían la conexión. Si no se puede
  renovar, la conexión pasa a «Expirada» y no se publica. El sondeo horario
  (`social:refresh-tokens`) ya no renueva lo comprobado en las últimas 12 h.
- **`MediaFile`** (en `PublishPayload::$mediaFiles`): las redes que exigen subir los
  bytes (LinkedIn, X, YouTube, TikTok) abren el archivo de la biblioteca como
  stream, sin pasar por HTTP ni cargarlo entero en memoria. `PublishPayload::$title`
  lleva el título interno del contenido (redes con título propio, como YouTube).
- **`HasPublishingLimits`**: caracteres y nº de archivos por publicación. El editor
  muestra el contador y avisos, y `PublicationPlanner` los valida al programar
  (además de que la red admita imágenes/video).
- **`HasApiVersion`**: versión de la API configurable en SUPERADMIN (Graph de Meta,
  cabecera `LinkedIn-Version`…), con su formato validado.
- **`CountsText`**: longitud propia del texto (X: las URLs cuentan 23 y emojis/CJK 2),
  usada al validar el límite.
- **`ProvidesPublishOptions`**: redes que obligan a que la persona elija opciones antes
  de publicar (TikTok, YouTube). El editor las pide en un panel propio de la variante,
  consulta en vivo cada cuenta (`GET /variants/{variant}/publish-options`, 30/min) y
  las guarda en `PostVariant::$options` (llegan al adaptador en
  `PublishPayload::$options`). `PublicationPlanner` no deja programar si faltan y el
  adaptador las revalida contra la cuenta al publicar. Como el resto del contenido,
  sólo se editan antes de aprobarlo.

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
- **Historias** (Page Stories API, sin texto): foto → `/{page}/photos` con
  `published=false` y `/{page}/photo_stories` con su `photo_id`; video →
  `/{page}/video_stories` con `upload_phase=start` (devuelve `video_id` y `upload_url`),
  subida por URL a `rupload.facebook.com` (cabeceras `Authorization: OAuth …` y
  `file_url`; el token sólo se envía a ese dominio) y `upload_phase=finish`. Video
  vertical 9:16 de 3 a 60 s. El `post_id` se guarda en el checkpoint (un reintento no la
  duplica) y el enlace sale de `/{page}/stories` (si no, el de la Página). Mismos
  permisos que publicar.
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
- **Historias**: el mismo flujo con `media_type=STORIES` e `image_url` o `video_url`,
  **sin pie** (Instagram no lo admite en historias), esperando a `FINISHED` si es video;
  cuentan en el límite de 100 publicaciones por API cada 24 h.
- **Métricas**: `followers_count`/`media_count` + Insights `reach`, `views`,
  `total_interactions` (`metric_type=total_value`); por publicación `like_count`,
  `comments_count` + `views`, `reach`, `shares`.
- **Inbox**: comentarios de las publicaciones y respuesta con `/{comment}/replies`.
- Scopes: `instagram_basic`, `instagram_content_publish`,
  `instagram_manage_comments`, `instagram_manage_insights`, `pages_show_list`,
  `pages_read_engagement`.

### Historias (tipo de contenido «Historia»)
Un contenido de tipo `story` se publica como historia en cada red
(`PublishPayload::$format = 'story'`; el tipo manda sobre el formato de la variante).
Sólo admiten historias las redes con `Capability::STORY` (Instagram, Facebook y el
proveedor de prueba). `PublicationPlanner` exige esa capacidad y **exactamente una**
imagen o video por red, y no aplica los límites de texto porque el texto no se envía.
Las métricas de las historias no se guardan (caducan a las 24 h y las redes sólo las
dan mientras siguen activas), así que no cuentan para la analítica ni los mejores
horarios. En el editor: sólo se ofrecen redes con historias, se oculta el texto por red
y el selector de medios admite un archivo.

### Validación antes de publicar
`PublicationPlanner` valida al **programar** y al **publicar ahora**: la red debe
tener cuentas conectadas y activas en la marca, y recibir lo que exige (Instagram:
al menos una imagen o video; sin video o sin imágenes en redes que no los admiten),
respetar sus límites de texto y archivos (`HasPublishingLimits`: Instagram 2200
caracteres y 10 archivos; Facebook un video por publicación…) y llevar las opciones
obligatorias de la red (`ProvidesPublishOptions`). Errores → 422.

### SUPERADMIN
Integraciones sociales permite habilitar cada red, guardar sus credenciales
(cifradas; cada red con su nombre: App ID, Client key…), **Probar conexión**
(`POST /platform/social-providers/{p}/test`), ajustar la versión de la API
(`api_version`; `graph_version` se mantiene por compatibilidad) y los scopes (admite
scopes con forma de URL, como los de Google), y copiar las URLs que pide cada
proveedor con la indicación de dónde registrarlas. Cada red lista además sus
**permisos opcionales** (`optional_scopes`: no se piden por defecto y activan funciones,
p. ej. `threads_delete` para borrar en Threads o los de páginas de LinkedIn) con lo que
activa cada uno y un botón para añadirlo; hay que aprobarlos antes en la app de la red y
reconectar las cuentas ya conectadas.

## LinkedIn — implementado (`LinkedInProvider`)

Revisado contra la documentación oficial (learn.microsoft.com/linkedin, sep-2026).

- **OAuth**: `https://www.linkedin.com/oauth/v2/authorization` y
  `…/oauth/v2/accessToken` con `client_secret` (LinkedIn sólo documenta PKCE para
  apps nativas). Token de **60 días**; refresh token sólo para socios aprobados del
  Marketing Developer Platform, así que normalmente la conexión caduca y se
  reconecta (la app avisa al caducar). Pedir scopes distintos invalida los tokens
  anteriores: mantener un único conjunto por app.
- **Scopes por defecto** (productos de alta directa «Sign In with LinkedIn using
  OpenID Connect» + «Share on LinkedIn»): `openid profile email w_member_social` →
  publicar en el **perfil** del miembro. Las **páginas de empresa** exigen la
  *Community Management API* (revisión de LinkedIn, sólo empresas registradas;
  LinkedIn pide solicitarla en una app sin otros productos): se añaden en SUPERADMIN
  `w_organization_social r_organization_social rw_organization_admin
  r_organization_social_feed w_organization_social_feed` (y opcionalmente
  `r_member_postAnalytics r_member_profileAnalytics`). El adaptador sólo usa lo que
  el token concede.
- **API versionada**: cabeceras `LinkedIn-Version` (por defecto `202609`,
  configurable en SUPERADMIN; LinkedIn mantiene cada versión ≥ 1 año y la más antigua
  vigente hoy, 202510, se retira el 15-oct-2026) y `X-Restli-Protocol-Version: 2.0.0`.
  Sin llamadas BATCH_GET (bloqueadas en el nivel de desarrollo de la CM API).
- **Destinos**: el perfil (`urn:li:person:{sub}` de `/v2/userinfo`) y, con
  `rw_organization_admin`, las páginas donde es ADMINISTRATOR (`organizationAcls`,
  nombre con `organizations/{id}`; máx. 25).
- **Publicar** (`POST /rest/posts`, el id llega en la cabecera `x-restli-id`): texto
  (máx. 3000; se escapa el formato «little text» salvo los hashtags), una imagen,
  2–20 imágenes (`multiImage`) o un video (MP4 hasta 500 MB: `initializeUpload` → PUT
  de cada parte sin token → `finalizeUpload` con los ETag → espera a `AVAILABLE`). No
  se mezclan video e imágenes. Las imágenes se suben con el token. Crear no es
  idempotente en LinkedIn: el checkpoint guarda los medios subidos y la publicación
  creada, y un reintento no la duplica. URL: `https://www.linkedin.com/feed/update/{urn}/`.
- **Métricas**: páginas → `networkSizes` (seguidores) y
  `organizationalEntityShareStatistics` (impresiones, alcance, reacciones,
  comentarios, compartidos, clics; últimos 12 meses). Perfil → seguidores con
  `r_member_profileAnalytics` y métricas de la publicación con
  `r_member_postAnalytics` (una métrica por llamada). Sin esos permisos, 0.
- **Inbox**: comentarios de las publicaciones recientes de las **páginas**
  (`socialActions/{urn}/comments`) y respuesta en su nombre (el id de la conversación
  lleva la página). En perfiles LinkedIn no permite leerlos.
- **Almacenamiento** (política de LinkedIn): la actividad social de miembros sólo
  48 h y la de organizaciones 6 semanas–6 meses; los ids/URN, sin límite.

## Threads — implementado (`ThreadsProvider`)

- **App propia**: el caso de uso «Acceder a la API de Threads» tiene su propio *Threads
  App ID/secret* (distinto del de Facebook) y Meta no lo permite en la misma app que
  «Facebook Login»: en la práctica, una app de Meta aparte. Sin PKCE.
- **Tokens**: el código (válido 1 h) se canjea en `graph.threads.net/oauth/access_token`
  por un token de 1 h que se cambia al momento por uno de **60 días**
  (`th_exchange_token`). Ese token se renueva consigo mismo (`th_refresh_token`, con
  ≥ 24 h de antigüedad), así que se guarda también como refresh token; el permiso de la
  persona dura 90 días y se amplía al renovar.
- **Scopes**: `threads_basic threads_content_publish threads_read_replies
  threads_manage_replies threads_manage_insights`. Hasta el App Review sólo funcionan la
  cuenta propia y las de *Threads Testers*.
- **API** `graph.threads.net/v1.0` (única versión publicada; configurable). Publicar:
  contenedor (`/{user}/threads`: TEXT, IMAGE, VIDEO o CAROUSEL de 2–20 hijos) → espera a
  `FINISHED` → `threads_publish` → `permalink`. Los archivos se descargan de URLs
  públicas (como Instagram). Contenedores y media publicados se guardan en el
  checkpoint: un reintento retoma el mismo contenedor y nunca publica dos veces.
  Límite: 250 publicaciones y 1000 respuestas por perfil cada 24 h; texto ≤ 500 y
  hasta 5 enlaces.
- **Métricas**: `threads_insights` (views, likes, replies, reposts, quotes,
  followers_count) y `/{media}/insights`. **Inbox**: respuestas de primer nivel a las
  publicaciones recientes (sin las propias) y respuesta con `reply_to_id`.

## X — implementado (`XProvider`)

- **OAuth 2.0 con PKCE** (`x.com/i/oauth2/authorize`, `api.x.com/2/oauth2/token`) como
  cliente confidencial (app tipo *Web App*, secreto por HTTP Basic). El **código caduca a
  los 30 s** (se canjea en el propio callback). Token de 2 h; refresh token de un solo
  uso (rota en cada renovación) → renovación con bloqueo por conexión.
- **Scopes**: `tweet.read tweet.write users.read media.write offline.access`.
- **Publicar** (`POST /2/tweets`): texto de 280 caracteres **ponderados** (URL = 23,
  emoji/CJK = 2), hasta 4 imágenes (subida directa `POST /2/media/upload`) o un video/GIF
  (`/2/media/upload/initialize` → `append` en partes de 4 MB → `finalize` → estado). Los
  archivos subidos (caducan en 24 h) y la publicación creada van al checkpoint: X no
  tiene clave de idempotencia y cobra cada intento.
- **Coste y restricciones (2026)**: sólo existe el **pago por uso** — cada publicación y
  cada archivo subido se cobran (más si el texto lleva una URL) y cada publicación leída
  (métricas, menciones) también. Fuera de Enterprise sólo se puede responder a quien
  menciona a la cuenta y las @menciones a terceros están limitadas; los 403 de X se
  muestran con su detalle.
- **Métricas**: públicas y, en los últimos 30 días, impresiones y clics privados.
  **Inbox**: menciones de los últimos 3 días (`/2/users/{id}/mentions`), respuesta con
  `reply.in_reply_to_tweet_id`.

## YouTube — implementado (`YouTubeProvider`)

- **OAuth de Google** con `access_type=offline` + `prompt=consent` (refresh token) y PKCE.
  Scopes: `youtube.upload`, `youtube.readonly` y `youtube.force-ssl` (el único que
  permite leer y responder comentarios). Requiere **verificación de la app OAuth**
  (scopes sensibles; hasta entonces, pantalla de «app no verificada» y máx. 100 usuarios;
  en modo pruebas los refresh tokens caducan a los 7 días).
- **Subida reanudable** (`upload/youtube/v3/videos?uploadType=resumable`): la sesión se
  guarda en el checkpoint y un reintento consulta los bytes recibidos (`308` + `Range`) y
  continúa desde ahí. Título ≤ 100 (sin `<>`), descripción ≤ 5000 bytes, categoría 22.
- **Opciones obligatorias** (Required Minimum Functionality): la persona elige el
  título, la visibilidad (público, oculto o privado) y si es **contenido para niños**, y
  puede declarar contenido sintético; el panel muestra el aviso de certificación con
  enlace a las Condiciones de YouTube.
- **Shorts**: no hay marca en la API; YouTube clasifica como Short un video vertical o
  cuadrado de hasta 3 minutos.
- **Límites**: `videos.insert` tiene cupo propio de **100 subidas al día por proyecto**
  (compartido por todos los clientes); ampliar el cupo y quitar el **bloqueo en privado**
  de los proyectos no auditados exige la auditoría de YouTube API Services.
- **Métricas**: canal (`subscriberCount`, `viewCount`, `videoCount`) y video (vistas, me
  gusta, comentarios). **Inbox**: comentarios de los videos del canal y respuesta con
  `comments.insert`.

## TikTok — implementado (`TikTokProvider`)

- **Login Kit v2** (`www.tiktok.com/v2/auth/authorize/`, `client_key`, scopes separados
  por comas; sin PKCE en web). Token de 24 h y refresh token de 365 días. Scopes:
  `user.info.basic user.info.stats video.publish video.list`.
- **Direct Post de video** (`post/publish/video/init/` con `FILE_UPLOAD`): una parte
  hasta 64 MB y, por encima, partes de 10 MB (la última absorbe el resto), subidas en
  orden con `Content-Range`; después se consulta `status/fetch` hasta
  `PUBLISH_COMPLETE`. El `publish_id` va al checkpoint (TikTok no tiene clave de
  idempotencia): un reintento consulta el estado en lugar de volver a subir. Las fotos
  sólo admiten descarga desde un dominio verificado (`PULL_FROM_URL`): no se ofrecen.
- **Opciones obligatorias** (Content Sharing Guidelines): el panel muestra la cuenta
  que publica (`creator_info`), pide la privacidad **sin valor por defecto** (sólo las
  que admite la cuenta), las interacciones (comentar, dúo, stitch) **desmarcadas** y
  bloqueadas si la cuenta las desactivó, la divulgación de contenido comercial («Tu
  marca» / «Contenido de marca», que no puede ser privado) y la aceptación de la
  Confirmación de uso de música (y de la Política de contenido de marca si aplica).
  Al publicar se vuelve a consultar la cuenta: si la privacidad ya no está disponible o
  la cuenta no puede publicar, falla con el motivo.
- **Auditoría**: sin ella, TikTok deja **todo en privado («Solo yo»)**, con cuentas
  privadas y un máximo de 5 personas publicando al día. Límite por creador (~15 al día).
- **Métricas**: cuenta (seguidores, me gusta, videos) y videos **públicos**
  (`video/query/`); un video privado o en moderación no tiene id público y queda con el
  `publish_id` (métricas 0). **Sin inbox**: TikTok no ofrece comentarios a apps
  comerciales.

## Cumplimiento para Meta App Review

Meta exige, además de la app (App ID/Secret) y el OAuth redirect HTTPS, tres cosas
que el App Review revisa. Ya están construidas del lado del código:

- **Política de Privacidad (URL pública):** `/privacidad` (SPA, sin login).
- **Términos de servicio (URL pública):** `/terminos`.
- **Borrado de datos:** `/eliminar-datos` (instrucciones + consulta de estado) y el
  **callback firmado** `POST /api/v1/data-deletion/{facebook|threads}` (cada app de
  Meta firma con su secreto: la de Facebook cubre Facebook e Instagram; Threads tiene
  la suya), que:
  - verifica el `signed_request` con HMAC-SHA256 y el App Secret de esa app;
  - registra la solicitud (`data_deletion_requests`) y lanza `PurgeExternalUserData`
    (borra, **sólo en las redes de esa app**, las conexiones cuya cuenta pertenece al
    usuario y sus conversaciones de inbox);
  - responde con `{ url, confirmation_code }` (formato requerido por Meta).
  - Está exento de CSRF (llamada servidor-a-servidor).
- **Desautorización** (la persona quita la app desde Facebook o Threads):
  `POST /api/v1/deauthorize/{facebook|threads}` con el mismo `signed_request` → sus
  conexiones pierden los tokens y quedan «Expiradas» (con aviso para reconectar).
  SUPERADMIN muestra las dos URLs de cada app para registrarlas.

## Revocar el acceso al desconectar

Al desconectar una cuenta, si la red lo permite (`RevokesAccess`: YouTube —sus
políticas lo exigen—, X y TikTok) también se revoca el acceso en la red. No se
revoca si otra conexión (de otra marca u organización) usa la misma cuenta, porque la
red invalidaría también sus tokens, y un fallo de la red no impide desconectar (queda
en el registro y la auditoría indica `revoked_remotely`).

## Borrar publicaciones (`DeletesRemotePosts`)

Las redes cuya API lo permite implementan `deleteRemotePost()` (docs/12, «Borrar de las
redes lo publicado»). Es idempotente: si la red confirma que la publicación ya no
existe, cuenta como borrada.

| Red | Llamada | Notas |
|---|---|---|
| Facebook | `DELETE /{post-id}` con el token de la Página | `pages_manage_posts` (ya se pide). Graph responde igual a «no existe» que a «sin permiso» (código 100, subcódigo 33): sólo se da por borrada si un `GET` confirma que no existe. |
| Threads | `DELETE graph.threads.net/v1.0/{id}` | Exige **`threads_delete`**, que no se pide por defecto: añadirlo al caso de uso de la app de Meta y a los scopes de Threads en SUPERADMIN (aparece entre sus «permisos opcionales», con botón para añadirlo), y reconectar las cuentas. Sin él se explica cómo activarlo. Máximo 100 borrados al día por cuenta. |
| X | `DELETE /2/tweets/{id}` | `tweet.write` (ya se pide). `resource-not-found` cuenta como borrada. |
| LinkedIn | `DELETE /rest/posts/{urn codificado}` + `X-RestLi-Method: DELETE` | Idempotente en LinkedIn (204). `w_member_social` o, para páginas, `w_organization_social`. |
| YouTube | `DELETE /youtube/v3/videos?id=` | `youtube.force-ssl` (ya se pide); 50 unidades de la cuota diaria del proyecto. |
| Instagram, TikTok | — | Sus APIs de publicación no permiten borrar: Loop7 indica que se borre desde la red. |

## Webhooks de TikTok

`POST /api/v1/social/webhooks/tiktok` (URL que SUPERADMIN registra en la app de
TikTok): firma `TikTok-Signature: t=…,s=…` (HMAC-SHA256 hexadecimal de «t.cuerpo» con el
client secret) y `client_key` de la app. TikTok entrega al menos una vez y reintenta
72 h: cada aviso se procesa una sola vez.
- `authorization.removed` → las conexiones de esa cuenta pierden los tokens y quedan
  «Expiradas».
- `post.publish.publicly_available` → la publicación guarda el id público del video y
  su enlace (desde entonces tiene métricas).

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
