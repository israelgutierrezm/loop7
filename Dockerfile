# syntax=docker/dockerfile:1
# Imagen única del producto Loop7: SPA (Vue) + API (Laravel) servidos por Nginx +
# PHP-FPM en el mismo origen (ideal para la auth por cookies de Sanctum).
# Publicada en GHCR por .github/workflows/deploy.yml.

# --------------------------------------------------------------------------
# 1) Build del SPA (Vue + Vite)
# --------------------------------------------------------------------------
FROM node:22-alpine AS frontend
WORKDIR /app/frontend
COPY apps/frontend/package.json apps/frontend/package-lock.json ./
RUN npm ci
COPY apps/frontend/ ./
RUN npm run build

# --------------------------------------------------------------------------
# 2) Dependencias PHP (sin dev, autoloader optimizado)
# --------------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app/backend
COPY apps/backend/ ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist \
    --no-scripts --ignore-platform-reqs

# --------------------------------------------------------------------------
# 3) Runtime: PHP-FPM + Nginx + Supervisor
# --------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS runtime

# Extensiones PHP requeridas por la app (pdo_mysql, intl, gd, zip, bcmath,
# opcache, pcntl para colas, mbstring y redis para caché/cola/sesión en prod).
RUN apk add --no-cache nginx supervisor icu-libs libzip libpng libjpeg-turbo freetype oniguruma \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath intl zip gd opcache pcntl mbstring \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

WORKDIR /var/www/html

# Código + dependencias del backend y el SPA compilado.
COPY --from=vendor /app/backend ./
COPY --from=frontend /app/frontend/dist /var/www/spa

# Configuración de runtime.
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache /var/www/spa

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=25s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8080/up >/dev/null 2>&1 || exit 1

ENTRYPOINT ["entrypoint"]
