# Panel SUPERADMIN

Panel de la plataforma (`/platform`), separado de los roles de cliente: sólo usuarios con
`is_platform_admin`. Toda acción que cambia algo queda en la auditoría global.

## Dashboard
MRR, ingresos y conversiones de los últimos 30 días, bajas, pagos fallidos, suscripciones
por estado, organizaciones y usuarios, publicaciones (7 días) publicadas/fallidas/
programadas, uso de IA (créditos del mes y generaciones), y salud operativa (jobs fallidos,
webhooks fallidos, conexiones sociales que requieren atención).

## Menú (implementado)
| Sección | Qué se gestiona |
|---|---|
| Dashboard | Métricas de negocio y operación |
| Organizaciones | Listado, suspensión/activación y **detalle de facturación**: plan de cortesía o cambio de plan, ampliar prueba, cancelar, excepciones de límites (overrides), add-ons y facturas |
| Usuarios | Listado e impersonación |
| Suscripciones | Todas las suscripciones con filtro por estado |
| Planes | Planes, precios por moneda/intervalo, límites y funciones, add-ons |
| Pagos y facturas | Facturas (marcar pagada / anular), transacciones y bitácora de webhooks (con reintento y código de verificación de Openpay) |
| Pasarelas | Activación, entorno, credenciales cifradas y enmascaradas, "Probar conexión" |
| Redes sociales | Proveedores (Meta), credenciales, versión de Graph API, URLs de la app |
| Proveedores de IA | Activación, credenciales cifradas, modelos (se refrescan desde la API del proveedor) |
| Colas | Jobs fallidos: reintentar u olvidar |
| Auditoría global | Todas las acciones, filtrables por acción u organización |
| Configuración | Datos legales de la empresa (páginas legales), registro abierto/cerrado, plan y días de prueba, días de gracia, aviso global |

Las "feature flags" se gestionan como funciones de plan (`feature.*`) y excepciones por
organización; los avisos del sistema, como el aviso global de Configuración.

## Impersonación
- explícita y auditada (inicio, fin y fin por caducidad);
- no se puede impersonar a otro administrador de plataforma;
- banner visible mientras dura, con botón para finalizar;
- **caduca a los 60 minutos** (`GuardImpersonation`): la siguiente petición devuelve la
  sesión al administrador (`401 impersonation_expired`) y el SPA vuelve a `/platform`;
- **acciones bloqueadas** mientras dura (`403 impersonation_blocked`): contraseña, MFA,
  pagos/suscripción, API keys, claves de IA y borrado de la organización;
- toda acción auditada durante la impersonación incluye `impersonated_by` (id público
  del administrador que actúa);
- no revela secretos (las credenciales siguen cifradas y enmascaradas).
