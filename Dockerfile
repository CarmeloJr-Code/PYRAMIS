# syntax=docker/dockerfile:1

# ---------- build ----------
FROM dunglas/frankenphp:php8.4-alpine AS build

RUN install-php-extensions pdo_pgsql intl zip opcache pcntl \
 && apk add --no-cache nodejs npm
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# PHP deps first so they cache independently of app code
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# JS deps next
COPY package.json ./
RUN npm install --no-audit --no-fund

COPY . .

# package:discover (run by post-autoload-dump) needs a bootable app
RUN cp .env.example .env \
 && composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && npm run build \
 && rm -rf node_modules .env

# ---------- runtime ----------
FROM dunglas/frankenphp:php8.4-alpine

RUN install-php-extensions pdo_pgsql intl zip opcache pcntl

WORKDIR /app
COPY --from=build /app /app
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

# Render runs containers with no-new-privileges, so exec'ing a binary that
# carries file capabilities fails with EPERM. We bind $PORT, never :80.
RUN setcap -r /usr/local/bin/frankenphp

RUN chmod +x /usr/local/bin/entrypoint \
 && mkdir -p storage/framework/cache/data \
             storage/framework/sessions \
             storage/framework/views \
             storage/logs \
             bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

ENV SERVER_NAME=":8080"
EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint"]
