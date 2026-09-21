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
