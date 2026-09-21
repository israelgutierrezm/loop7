# Prompt inicial para Claude Code

Lee primero, en este orden:
1. `CLAUDE.md`
2. `README.md`
3. todos los archivos dentro de `docs/`
4. `database/ESQUEMA_BASE.md`
5. `database/ERD.mmd`
6. `config/.env.example`
7. `infra/docker-compose.example.yml`

Quiero que construyas desde cero este SaaS completo utilizando Laravel 12 + Vue 3 + TypeScript + MySQL + Redis siguiendo estrictamente la arquitectura y reglas indicadas.

## Objetivo del producto
Una plataforma SaaS de gestión profesional de redes sociales que permita a cualquier cliente registrarse, contratar una membresía, crear marcas, conectar de forma segura sus cuentas de Facebook, Instagram, Threads, LinkedIn, TikTok, X, YouTube, Pinterest, Google Business Profile, Bluesky, Mastodon y futuras redes, crear contenido manualmente o mediante IA externa, adaptar una pieza a cada red, realizar aprobaciones, programar y publicar mediante colas, recibir métricas, generar reportes y administrar equipos con permisos granulares.

El dueño del SaaS tendrá un panel SUPERADMIN independiente para gestionar usuarios, Organizations, planes, suscripciones, pagos, pasarelas, integraciones, proveedores IA, feature flags, jobs, logs, auditoría y salud del sistema.

## Método de trabajo obligatorio
No intentes construir todo en una sola iteración. Trabaja por fases según `docs/14_ROADMAP_IMPLEMENTACION.md`.

Para cada fase:
1. Explica brevemente qué vas a implementar.
2. Crea las migraciones/modelos/endpoints/componentes necesarios.
3. Ejecuta migraciones y seeders.
4. Implementa pruebas.
5. Ejecuta test suite y linters.
6. Corrige fallos antes de continuar.
7. Actualiza documentación.
8. Haz un commit con Conventional Commits si el entorno Git está disponible.

## Requisitos visuales obligatorios
El panel autenticado debe utilizar un Admin Layout profesional con:
- sidebar izquierdo;
- sidebar colapsable en escritorio;
- drawer en móvil;
- logo arriba;
- selector de Organization/Brand cuando corresponda;
- navegación agrupada;
- topbar superior;
- breadcrumbs;
- título de página;
- área principal limpia;
- menú de usuario;
- indicador de notificaciones;
- diseño responsive;
- estados loading/empty/error/skeleton;
- modo claro preparado para modo oscuro futuro.

No copies visualmente productos existentes. Diseña una identidad propia, moderna, limpia y orientada a SaaS B2B.

## Seguridad
La seguridad es prioridad máxima. Implementa desde el primer módulo el aislamiento por Organization, Policies, auditoría y protección contra IDOR. Nunca permitas que un token OAuth o secreto salga hacia Vue. Nunca almacenes contraseñas de redes sociales. Usa OAuth oficial. Los secretos deben almacenarse cifrados. Los webhooks deben verificar firma, evitar replay cuando el proveedor lo soporte y ser idempotentes.

## Idioma
La aplicación visible para usuarios estará en español. Código técnico e identificadores pueden conservar nombres ingleses cuando sean estándar y mejoren mantenibilidad.

Comienza creando la base del repositorio, arquitectura modular, autenticación, modelo Organization, SUPERADMIN, roles/permisos, Admin Layout y pruebas de aislamiento. No avances a integraciones sociales hasta que esa base esté estable y probada.
