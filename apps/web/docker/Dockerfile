# Tintype web image: one image, several roles (web | horizon | reverb | scheduler | migrate).
# Build from the repo root:  docker build -f apps/web/docker/Dockerfile -t tintype/web .
# Pinned: PHP 8.4.27 fixes CVE-2026-109618 / CVE-2026-109619. Bump the tag and digest together.
FROM dunglas/frankenphp:1.13.3-php8.4.27-bookworm@sha256:a3d212a043a87f5a31fc13e0b564a857eba2592e9326f004bc43d405a3463b11 AS base
RUN php -r 'exit(version_compare(PHP_VERSION, "8.4.27", ">=") ? 0 : 1);' \
 && install-php-extensions pdo_pgsql pdo_sqlite zip pcntl gd intl opcache bcmath
ENV SERVER_NAME=":8080" \
    APP_NAME=Tintype \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app

FROM base AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates gnupg unzip git \
 && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
 && apt-get install -y --no-install-recommends nodejs && rm -rf /var/lib/apt/lists/*
COPY apps/web/composer.json apps/web/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY apps/web/package.json apps/web/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY apps/web/ ./
RUN composer dump-autoload --optimize --no-dev \
 && cp .env.example .env && php artisan key:generate --force \
 && npm run build \
 && rm -rf node_modules .env

FROM base
COPY --from=build /app /app
COPY apps/web/docker/entrypoint.sh /usr/local/bin/tintype-entrypoint
RUN chmod +x /usr/local/bin/tintype-entrypoint \
 && mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/app/tintype bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache /data /config
USER www-data
EXPOSE 8080
ENTRYPOINT ["tintype-entrypoint"]
CMD ["web"]
