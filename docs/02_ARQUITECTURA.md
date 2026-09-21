# Arquitectura técnica

## Estilo
Modular Monolith inicialmente.

## Módulos de dominio
- Identity
- PlatformAdmin
- Organizations
- Brands
- AccessControl
- SocialConnections
- Content
- Campaigns
- Publishing
- Calendar
- MediaLibrary
- AI
- Analytics
- Inbox
- Automation
- Billing
- Payments
- Notifications
- Audit
- Integrations
- Reporting

## Dependencias
Los módulos se comunican mediante Services/Actions y Events. Evitar dependencias circulares.

## Capas sugeridas por módulo
```
Modules/<Modulo>/
  Domain/
  Application/
  Infrastructure/
  Http/
```
No es obligatorio usar DDD ceremonial. Mantener pragmatismo.

## Flujo de publicación
Vue -> API Laravel -> validación -> autorización -> persistencia -> Job -> Redis -> Horizon Worker -> SocialProvider -> API externa -> resultado -> métricas/auditoría/notificación.

## Escalamiento
- Web stateless.
- Redis compartido.
- Workers escalables horizontalmente.
- Archivos en object storage.
- CDN para media pública.
- DB primaria con estrategia de backups y posibilidad futura de réplicas de lectura.

## Idempotencia
Obligatoria para:
- publicación social;
- procesamiento de webhooks;
- alta/cambio de suscripción;
- cobros;
- importaciones y sincronizaciones externas.
