# Guía de desarrollo local

Monorepo: `apps/backend` (Laravel 12 API) y `apps/frontend` (Vue 3 + TS SPA).

## Requisitos
- PHP 8.3+ con extensiones: pdo_mysql, pdo_sqlite, mbstring, openssl, intl, gd, zip, bcmath
- Composer 2
- Node 22 + npm
- MySQL 8 (o compatible)
- Redis (opcional en Fase 0-1; se usa `database` como driver de cola/caché/sesión mientras tanto)

## Backend (`apps/backend`)
```bash
composer install
cp .env.example .env
php artisan key:generate
# Configura DB_* en .env (por defecto: MySQL en 127.0.0.1:3306, base social_saas)
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8000
```
La API queda en `http://127.0.0.1:8000/api/v1`. Health check: `GET /api/v1/health`.

### Colas y scheduler (publicación)
El motor de publicación usa jobs y un scheduler. En desarrollo, con el driver
`database`, ejecuta en terminales aparte:
```bash
php artisan queue:work --queue=publishing,default,inbox,analytics,automations
php artisan schedule:work   # publica lo vencido, sincroniza métricas/inbox, renueva tokens, vence suscripciones
```
En producción se usa Redis con los contenedores `worker` y `scheduler` de
`docker-compose.yml` (misma imagen, `queue:work` y `schedule:work`). Los trabajos
fallidos se operan desde el panel SUPERADMIN en `/platform/jobs`. Ver
[12_PUBLICACION_COLAS.md](12_PUBLICACION_COLAS.md).

Usuarios sembrados (solo desarrollo):
- SUPERADMIN: `superadmin@loop7.test` / `Superadmin123`
- OWNER demo: `owner@loop7.test` / `Owner12345`

### Calidad
```bash
vendor/bin/pint            # formateo PSR-12
vendor/bin/phpstan analyse # análisis estático (larastan, nivel 5)
php artisan test           # pruebas (SQLite en memoria)
```

## Frontend (`apps/frontend`)
```bash
npm install
npm run dev     # http://localhost:5173 (proxy /api y /sanctum al backend)
```
El proxy apunta a `http://127.0.0.1:8000` por defecto. Para usar otro puerto de
backend, crea `apps/frontend/.env.local` con:
```
VITE_API_PROXY_TARGET=http://127.0.0.1:8001
```

### Calidad
```bash
npm run type-check   # vue-tsc
npm run build        # build de producción
```

## Notas de entorno (Windows)
- El proxy de Vite usa `127.0.0.1` (no `localhost`) para evitar que resuelva a
  IPv6 (`::1`) y falle la conexión al backend.
- Si el servidor MySQL local usa MyISAM por defecto, no hay problema: la conexión
  fuerza `InnoDB ROW_FORMAT=DYNAMIC` desde `config/database.php`.
