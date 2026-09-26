# Admin Layout y UX

## Estructura desktop
```
┌──────────── Sidebar ────────────┬────────────────────────────────────┐
│ Logo                            │ Topbar                             │
│ Organization / Brand selector   ├────────────────────────────────────┤
│                                 │ Breadcrumbs                        │
│ Dashboard                       │ Título + acciones                   │
│ Contenido                       │                                    │
│  - Crear                        │ Main content                       │
│  - Biblioteca                   │                                    │
│  - Calendario                   │                                    │
│ Campañas                        │                                    │
│ Redes sociales                  │                                    │
│ Inbox                           │                                    │
│ Analítica                       │                                    │
│ Automatizaciones               │                                    │
│ Equipo                          │                                    │
│ Facturación                     │                                    │
│ Configuración                   │                                    │
│                                 │                                    │
│ Usuario / salir                 │                                    │
└─────────────────────────────────┴────────────────────────────────────┘
```

## Sidebar
- fijo en desktop;
- colapsable a iconos;
- drawer móvil;
- grupos y submenús;
- badges para errores/conexiones que requieren atención;
- ocultar únicamente por UX según permisos, pero backend siempre valida.

## Topbar
- breadcrumbs;
- buscador de comandos (botón «Buscar… Ctrl K» y atajo **Ctrl/⌘+K**);
- notificaciones;
- ayuda;
- menú usuario.

## Buscador de comandos
`components/layout/CommandPalette.vue`, en el panel de la organización:
- **Acciones**: crear contenido, nueva marca, nueva campaña, invitar, nuevo rol (cada
  una según permisos). Abren el formulario de su vista con `?crear=1` / `?invitar=1`
  (`useQueryAction`).
- **Ir a**: todas las secciones del menú a las que el usuario tiene acceso
  (`config/navigation.ts`), notificaciones y, para SUPERADMIN, el panel de plataforma.
- **Organizaciones**: cambiar a otra organización. **Cuenta**: perfil y cerrar sesión.
- **Resultados** de `GET /search?q=` (mín. 2 caracteres, 120/min): contenido (título o
  texto), marcas, campañas y miembros, 5 por tipo; cada tipo exige su permiso de lectura
  y respeta el acceso por marca y la Organization (módulo `Search`).
- Accesible: diálogo modal, combobox + listbox con `aria-activedescendant`, ↑↓ para
  moverse, Enter para abrir, Esc para cerrar; devuelve el foco al cerrar. Ignora
  acentos al filtrar.

## Diseño
- SaaS B2B moderno;
- limpio;
- bordes/radios consistentes;
- tablas profesionales;
- filtros persistibles;
- formularios con mensajes claros;
- skeletons y empty states;
- responsive real.

## Vistas clave
- Dashboard
- Content Composer multired
- Calendario
- Conexiones sociales
- Brand Brain
- Equipo/roles
- Plan/facturación
- Analytics
- SUPERADMIN separado visualmente.
