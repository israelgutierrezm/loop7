#!/bin/sh
set -e

cd /var/www/html

# Manifiesto de paquetes (composer se instaló con --no-scripts en el build).
php artisan package:discover --ansi || true

# Enlace de almacenamiento público (idempotente).
php artisan storage:link 2>/dev/null || true

# Cachea la configuración con las variables de entorno del contenedor.
# (No se cachean rutas por existir una ruta de health basada en closure.)
php artisan config:cache
php artisan event:cache || true

# Migraciones opcionales al arrancar (mejor ejecutarlas como job puntual en
# despliegues con múltiples réplicas). Actívalo con RUN_MIGRATIONS=true.
if [ "${RUN_MIGRATIONS}" = "true" ]; then
    echo "==> Ejecutando migraciones"
    php artisan migrate --force
fi

exec /usr/bin/supervisord -c /etc/supervisord.conf
