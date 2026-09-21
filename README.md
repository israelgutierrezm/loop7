# SaaS de Gestión de Redes Sociales con IA

Paquete de especificación funcional y técnica para construir una plataforma SaaS de gestión, creación, programación, publicación y análisis de contenido en múltiples redes sociales.

## Objetivo
Crear una aplicación web SaaS segura y escalable donde cada cliente pueda registrarse, contratar una membresía, crear una o varias marcas, conectar sus propias cuentas sociales mediante OAuth, generar contenido con proveedores externos de IA, aprobarlo, programarlo y publicarlo desde un panel central.

## Stack objetivo
- Backend: Laravel 12, PHP 8.3+
- Frontend: Vue 3, Composition API, TypeScript, Vite, Tailwind CSS
- Base de datos: MySQL 8+
- Cache/colas: Redis + Laravel Horizon
- Autenticación SPA/API: Laravel Sanctum
- Roles/permisos: spatie/laravel-permission con ámbito por Organization
- Almacenamiento: S3 compatible
- Infraestructura: Docker, Nginx, PHP-FPM, Redis, workers, scheduler
- API: REST /api/v1

> Laravel 12 se conserva por decisión de proyecto. La implementación debe quedar preparada para actualizar de versión mayor sin acoplamientos innecesarios.

## Idioma y convenciones
- Interfaz, documentación, mensajes, validaciones y textos al usuario: español.
- Código, clases, contratos, tablas y endpoints: inglés cuando sea convención natural del ecosistema y mejore mantenibilidad.
- Comentarios de código: solo cuando aporten contexto, preferentemente en español.
- No traducir términos estándar como OAuth, webhook, API, Job, Queue, DTO, Repository o Service si la traducción genera ambigüedad.

## Estructura del paquete
- `CLAUDE.md`: instrucciones permanentes para Claude Code.
- `prompts/PROMPT_INICIAL.md`: prompt para iniciar la construcción completa.
- `docs/`: especificación funcional y técnica.
- `database/`: modelo de datos y ERD de referencia.
- `config/`: variables de entorno de ejemplo.
- `infra/`: propuesta de Docker y despliegue.

## Principios no negociables
1. Seguridad por diseño y mínimo privilegio.
2. Nunca almacenar contraseñas de redes sociales.
3. Tokens OAuth y secretos cifrados y nunca expuestos al frontend.
4. Aislamiento estricto entre Organizations.
5. Autorización siempre validada en backend.
6. Publicación social mediante colas, nunca directamente desde el request HTTP.
7. Arquitectura Modular Monolith con límites claros.
8. Proveedores de redes, pagos e IA implementados mediante interfaces/adaptadores.
9. Toda operación crítica debe generar auditoría.
10. Cobertura de pruebas obligatoria para autorización, billing, publicación, OAuth y aislamiento tenant.

## Cómo iniciar con Claude Code
1. Descomprimir este paquete en la raíz del repositorio nuevo.
2. Abrir Claude Code en esa carpeta.
3. Pedirle leer `CLAUDE.md`, `README.md` y toda la carpeta `docs/`.
4. Copiar el contenido de `prompts/PROMPT_INICIAL.md` como instrucción inicial.
5. Claude debe trabajar por fases y dejar cada fase ejecutable, probada y documentada.
