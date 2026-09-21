# Definición del MVP comercial

## Incluye
- Registro/login/verificación
- Organization + OWNER
- roles/permisos esenciales
- una o más Brands según plan
- conexión Meta (Facebook/Instagram) primero
- Content Studio
- texto + imagen/carrusel donde API permita
- IA de texto mediante un provider
- Calendar
- aprobación simple
- publicación programada por Redis/Horizon
- Dashboard básico
- planes + trial
- Stripe + Mercado Pago + Openpay con habilitación por SUPERADMIN
- panel SUPERADMIN
- auditoría y seguridad base

## No bloquea arquitectura futura
El MVP debe utilizar desde el día uno los contratos SocialProvider, AIProvider y PaymentGateway, aunque inicialmente solo algunos adapters estén completos.
