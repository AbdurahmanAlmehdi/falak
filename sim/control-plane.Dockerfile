# syntax=docker/dockerfile:1.7
# Falak control plane image for the local simulation.
#   build context:               ../control-plane
#   additional context "deploy": ../deploy/control-plane  (the production runtime: Caddyfile + PHP-mode snippets,
#                                php.ini per SAPI, entrypoint, healthcheck), so the sim runs the same topology:
#                                panel in FrankenPHP worker mode, agent-api with its own thread pool.
# Roles (production entrypoint): web (panel; migrations with FALAK_MIGRATE=1), agent-api, horizon, reverb, scheduler.
# Differences from control-plane/Dockerfile: no baked agent binaries (the sim mounts the repository).

ARG PHP_IMAGE=dunglas/frankenphp:1.13.2-php8.4-trixie

# --- base: PHP runtime + extensions ---------------------------------------------------
FROM ${PHP_IMAGE} AS base
COPY --from=deploy php-server.ini php-cli.ini /tmp/falak-ini/
RUN install-php-extensions pdo_pgsql redis pcntl intl zip bcmath gmp sockets opcache \
 && cat "$PHP_INI_DIR/php.ini-production" /tmp/falak-ini/php-server.ini > "$PHP_INI_DIR/php.ini" \
 && cat "$PHP_INI_DIR/php.ini-production" /tmp/falak-ini/php-cli.ini > "$PHP_INI_DIR/php-cli.ini" \
 && rm -rf /tmp/falak-ini && mkdir -p /tmp/falak-opcache
COPY --from=deploy php.ini "$PHP_INI_DIR/conf.d/zz-falak.ini"
WORKDIR /app

# --- vendor: composer deps (no dev) -----------------------------------------------------
FROM base AS vendor
COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

# --- assets: frontend build with bun ----------------------------------------------------
FROM oven/bun:1.4.2 AS assets
WORKDIR /app
COPY package.json bun.lock ./
RUN --mount=type=cache,target=/root/.bun/install/cache bun install --frozen-lockfile
COPY . .
# ziggy-js is resolved from the Composer package (tsconfig path alias -> vendor/tightenco/ziggy).
COPY --from=vendor /app/vendor/tightenco/ziggy ./vendor/tightenco/ziggy
RUN bun run build

# --- app ------------------------------------------------------------------------------
FROM base AS app
COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=production \
    LOG_CHANNEL=stderr
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build
COPY --from=deploy Caddyfile php-classic.caddyfile php-worker.caddyfile /etc/frankenphp/
COPY --from=deploy --chmod=0755 entrypoint.sh /usr/local/bin/falak-entrypoint
COPY --from=deploy --chmod=0755 healthcheck.sh /usr/local/bin/falak-healthcheck
RUN rm -f bootstrap/cache/*.php \
 && composer dump-autoload --classmap-authoritative --no-dev --no-interaction \
 && APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan package:discover --ansi \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/falak /falak/ca \
 && rm -rf /tmp/falak-opcache && install -d -o www-data -g www-data -m 0700 /tmp/falak-opcache \
 && chown -R www-data:www-data storage bootstrap/cache
EXPOSE 8080
ENTRYPOINT ["falak-entrypoint"]
CMD ["web"]
