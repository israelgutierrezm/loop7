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
| Organizaciones | Listado y **ficha de soporte**: miembros (rol, estado, último acceso, «Impersonar»), marcas con sus cuentas, suspensión con motivo / reactivación (ambas auditadas), eliminación a petición del cliente y **facturación**: plan de cortesía o cambio de plan, ampliar prueba, cancelar, excepciones de límites (overrides), add-ons y facturas |
| Usuarios | Búsqueda paginada, filtro de bloqueados, bloquear/desbloquear, restablecer el doble factor e impersonación |
| Suscripciones | Todas las suscripciones con filtro por estado |
| Planes | Planes, precios por moneda/intervalo, límites y funciones, add-ons |
| Pagos y facturas | Facturas (marcar pagada / anular), transacciones y bitácora de webhooks (con reintento y código de verificación de Openpay) |
| Pasarelas | Activación, entorno, credenciales cifradas y enmascaradas, "Probar conexión" |
| Redes sociales | Proveedores (Meta), credenciales, versión de Graph API, URLs de la app |
| Proveedores de IA | Activación, credenciales cifradas, modelos (se refrescan desde la API del proveedor) |
| Colas | Pendientes por cola (vía `Queue::size`, sirve con database o Redis) y jobs fallidos: reintentar o descartar uno a uno o todos, auditado. El error se muestra sin secretos (`SecretRedactor`) |
| Auditoría global | Todas las acciones, filtrables por acción u organización |
| Configuración | Datos legales de la empresa (páginas legales), registro abierto/cerrado, plan y días de prueba, días de gracia, aviso global |

Las "feature flags" se gestionan como funciones de plan (`feature.*`) y excepciones por
organización; los avisos del sistema, como el aviso global de Configuración.

## Organizaciones: suspensión y eliminación
- **Suspender** (`POST /platform/organizations/{id}/suspend`, motivo opcional) corta todo:
  las rutas de la organización responden `403 organization_suspended` (el panel muestra
  el aviso y ofrece cambiar a otra organización), sus API keys dejan de autenticar, lo
  programado falla con «La organización está suspendida.» y las sincronizaciones de
  métricas e inbox la omiten (`Organization::operational()`). `activate` lo revierte.
- **Eliminar** (`DELETE /platform/organizations/{id}`, escribiendo el nombre) reutiliza la
  misma limpieza que cuando la elimina su propietario (`DeleteOrganization` →
  `OrganizationDeleted`); se rechaza (`409`) mientras la pasarela siga cobrando: primero
  se cancela la suscripción.

## Usuarios: acciones de soporte
Ninguna se aplica a otros administradores de plataforma (`403`) ni a la propia cuenta
(`422`). Todas quedan auditadas.

| Acción | Endpoint | Efecto |
|---|---|---|
| Bloquear | `POST /platform/users/{id}/block` | `users.blocked_at`. El login responde `403 account_blocked` (sólo tras validar la contraseña, para no revelar el bloqueo a terceros) y `EnsureAccountActive` cierra la sesión abierta en su siguiente petición; el SPA vuelve al login con el aviso. Sus organizaciones siguen funcionando para el resto del equipo |
| Desbloquear | `POST /platform/users/{id}/unblock` | Vuelve a poder iniciar sesión |
| Restablecer MFA | `POST /platform/users/{id}/reset-two-factor` | Borra secreto, códigos de recuperación y confirmación: la persona entra sólo con su contraseña y vuelve a activarlo. Hacerlo únicamente tras verificar su identidad por otro medio |

Una cuenta bloqueada no se puede impersonar (`422 account_blocked`). Para dar de baja a
una organización completa se usa la suspensión de Organizaciones.

## Impersonación
- explícita y auditada (inicio, fin y fin por caducidad);
- no se puede impersonar a otro administrador de plataforma ni a una cuenta bloqueada;
- banner visible mientras dura, con botón para finalizar;
- **caduca a los 60 minutos** (`GuardImpersonation`): la siguiente petición devuelve la
  sesión al administrador (`401 impersonation_expired`) y el SPA vuelve a `/platform`;
- **acciones bloqueadas** mientras dura (`403 impersonation_blocked`): contraseña, MFA,
  pagos/suscripción, API keys, claves de IA y crear, transferir o borrar organizaciones;
- toda acción auditada durante la impersonación incluye `impersonated_by` (id público
  del administrador que actúa);
- no revela secretos (las credenciales siguen cifradas y enmascaradas).
