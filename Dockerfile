# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Base: FrankenPHP (Caddy + PHP 8.4) with the extensions the app needs.
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4 AS base

RUN install-php-extensions \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        zip \
    && apt-get update \
    && apt-get install -y --no-install-recommends supervisor \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# ---------------------------------------------------------------------------
# Composer dependencies (production only).
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi

# ---------------------------------------------------------------------------
# Frontend build. Wayfinder runs `php artisan wayfinder:generate` inside the
# Vite build, so this stage has PHP + vendor and gets Node copied in.
# ---------------------------------------------------------------------------
FROM vendor AS assets

COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

RUN npm ci --no-audit --no-fund && npm run build

# ---------------------------------------------------------------------------
# Runtime image.
# ---------------------------------------------------------------------------
FROM base AS app

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SERVER_NAME=:8080

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/app.ini $PHP_INI_DIR/conf.d/zz-app.ini

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build

RUN useradd --create-home --uid 1000 --shell /bin/bash laravel \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R laravel:laravel storage bootstrap/cache /config/caddy /data/caddy \
    && chmod +x docker/entrypoint.sh

USER laravel

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["supervisord", "-c", "/app/docker/supervisord.conf"]
