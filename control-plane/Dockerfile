# syntax=docker/dockerfile:1.7
# Falak control plane — production image (multi-arch: linux/amd64, linux/arm64).
#
#   docker buildx build -f control-plane/Dockerfile --build-arg FALAK_VERSION=v1.2.3 \
#     --platform linux/amd64,linux/arm64 -t ghcr.io/OWNER/falak-control-plane:v1.2.3 .
#
# Build context: the REPOSITORY ROOT (the image bakes agent/CLI/builder binaries, the agent protocol
# schemas and the Grafana dashboards next to the Laravel app). Ignore rules: Dockerfile.dockerignore.
#
# One image, six roles (entrypoint argument): web (the panel: FrankenPHP worker mode; runs migrations when
# FALAK_MIGRATE=1), agent-api (FrankenPHP for agent/builder long-polls, own thread pool), horizon, reverb,
# scheduler, relay (the Go terminal relay). Runs as www-data. Proven topology: sim/compose.yml; production: deploy/compose.yml.

ARG PHP_IMAGE=dunglas/frankenphp:1.12.7-php8.4-trixie
ARG GO_IMAGE=golang:1.25-trixie
# Caddy needs a newer Go than the agent does (v2.11.7: Go >= 1.26).
ARG CADDY_GO_IMAGE=golang:1.26-trixie

# --- binaries: falak-agent / falak / falak-builder / falak-relay for linux amd64+arm64 (cross-compiled once) ---
FROM --platform=$BUILDPLATFORM ${GO_IMAGE} AS binaries
ARG FALAK_VERSION=dev
WORKDIR /src/agent
COPY agent/go.mod agent/go.sum ./
RUN --mount=type=cache,target=/go/pkg/mod go mod download
COPY agent/ ./
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build \
    make agent builder relay bin/falak-linux-amd64 bin/falak-linux-arm64 VERSION="${FALAK_VERSION}" \
 && mkdir -p /out /out-relay \
 && cp bin/falak-relay-linux-amd64 bin/falak-relay-linux-arm64 /out-relay/ \
 && for f in falak-agent-linux-amd64 falak-agent-linux-arm64 falak-builder-linux-amd64 falak-builder-linux-arm64 falak-linux-amd64 falak-linux-arm64; do \
      cp "bin/$f" "/out/$f"; (cd /out && sha256sum "$f" > "$f.sha256"); \
    done \
 && cp bin/falak-agent-linux-amd64.version bin/falak-agent-linux-arm64.version /out/

# --- edge-caddy: Falak's Caddy for servers' edges (docs/plans/network/protection.md, path rate limits) -----------
# Stock Caddy (the apt package servers run) plus caddy-ratelimit (http.handlers.rate_limit) and caddy-dns/cloudflare
# (DNS-01, already required of edges). Served to agents at /install/edge/caddy-linux-<arch> with its checksum; an
# agent installs it when a site gets a rate limit or a DNS-01 certificate. Versions pinned; CADDY_VERSION must be at least
# the apt Caddy on servers (an older build lacks modules the edge has, and the agent refuses the swap).
FROM --platform=$BUILDPLATFORM ${CADDY_GO_IMAGE} AS edge-caddy
ARG CADDY_VERSION=v2.11.7
ARG XCADDY_VERSION=v0.4.7
ARG CADDY_RATELIMIT_VERSION=v0.1.0
ARG CADDY_DNS_CLOUDFLARE_VERSION=v0.2.4
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build \
    go install "github.com/caddyserver/xcaddy/cmd/xcaddy@${XCADDY_VERSION}" \
 && mkdir -p /out && cd /tmp \
 && for arch in amd64 arm64; do \
      CGO_ENABLED=0 GOOS=linux GOARCH=$arch xcaddy build "${CADDY_VERSION}" \
        --with "github.com/mholt/caddy-ratelimit@${CADDY_RATELIMIT_VERSION}" \
        --with "github.com/caddy-dns/cloudflare@${CADDY_DNS_CLOUDFLARE_VERSION}" \
        --output "/out/falak-caddy-linux-$arch" \
      && (cd /out && sha256sum "falak-caddy-linux-$arch" > "falak-caddy-linux-$arch.sha256") || exit 1; \
    done

# --- base: PHP runtime + extensions -----------------------------------------------------------------
FROM ${PHP_IMAGE} AS base
# php.ini (FrankenPHP) and php-cli.ini (CLI SAPI: artisan, Horizon, Reverb, scheduler) differ only in OPcache.
COPY deploy/control-plane/php-server.ini deploy/control-plane/php-cli.ini /tmp/falak-ini/
RUN install-php-extensions pdo_pgsql redis pcntl intl zip bcmath gmp sockets opcache \
 && cat "$PHP_INI_DIR/php.ini-production" /tmp/falak-ini/php-server.ini > "$PHP_INI_DIR/php.ini" \
 && cat "$PHP_INI_DIR/php.ini-production" /tmp/falak-ini/php-cli.ini > "$PHP_INI_DIR/php-cli.ini" \
 && rm -rf /tmp/falak-ini && mkdir -p /tmp/falak-opcache
COPY deploy/control-plane/php.ini "$PHP_INI_DIR/conf.d/zz-falak.ini"
WORKDIR /app

# --- vendor: composer deps (no dev) -------------------------------------------------------------------
FROM base AS vendor
COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY control-plane/composer.json control-plane/composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

# --- assets: frontend build with bun --------------------------------------------------------------
FROM --platform=$BUILDPLATFORM oven/bun:1.4.2 AS assets
WORKDIR /app
COPY control-plane/package.json control-plane/bun.lock ./
RUN --mount=type=cache,target=/root/.bun/install/cache bun install --frozen-lockfile
COPY control-plane/ ./
# ziggy-js is resolved from the Composer package (tsconfig path alias -> vendor/tightenco/ziggy).
COPY --from=vendor /app/vendor/tightenco/ziggy ./vendor/tightenco/ziggy
RUN bun run build

# --- app ------------------------------------------------------------------------------------------------
FROM base AS app
ARG FALAK_VERSION=dev
LABEL org.opencontainers.image.title="falak-control-plane" \
      org.opencontainers.image.description="Falak control plane (Laravel + FrankenPHP) with baked agent/CLI/builder binaries" \
      org.opencontainers.image.version="${FALAK_VERSION}" \
      org.opencontainers.image.licenses="MIT"
COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY control-plane/ ./
COPY --from=assets /app/public/build ./public/build
COPY --from=binaries /out/ /opt/falak/bin/
COPY --from=edge-caddy /out/ /opt/falak/bin/
# The terminal relay runs in this image (entrypoint role `relay`), so it is built for the image's own arch.
ARG TARGETARCH
COPY --from=binaries /out-relay/falak-relay-linux-${TARGETARCH} /usr/local/bin/falak-relay
COPY contracts/ /opt/falak/contracts/
COPY templates/ /opt/falak/templates/
COPY observability/grafana/dashboards/ /opt/falak/observability/grafana/dashboards/
COPY deploy/control-plane/Caddyfile deploy/control-plane/php-classic.caddyfile deploy/control-plane/php-worker.caddyfile /etc/frankenphp/
COPY --chmod=0755 deploy/control-plane/entrypoint.sh /usr/local/bin/falak-entrypoint
COPY --chmod=0755 deploy/control-plane/healthcheck.sh /usr/local/bin/falak-healthcheck
RUN rm -f bootstrap/cache/*.php .env \
 && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --classmap-authoritative --no-dev --no-interaction \
 && APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan package:discover --ansi \
 && rm /usr/bin/composer \
 && ln -sfn /app/storage/app/public /app/public/storage \
 && mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views \
             storage/logs storage/falak /falak/ca \
 && echo "${FALAK_VERSION}" > /opt/falak/VERSION \
 && rm -rf /tmp/falak-opcache && install -d -o www-data -g www-data -m 0700 /tmp/falak-opcache \
 && chown -R www-data:www-data storage bootstrap/cache /falak/ca /data/caddy /config/caddy \
 # File capabilities are not needed (port 8080) and would break under no-new-privileges.
 && setcap -r /usr/local/bin/frankenphp
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    FALAK_VERSION=${FALAK_VERSION} \
    FALAK_CA_PATH=/falak/ca \
    FALAK_AGENT_BINARIES_PATH=/opt/falak/bin \
    FALAK_BUILDER_BINARIES_PATH=/opt/falak/bin \
    FALAK_AGENT_SCHEMAS_PATH=/opt/falak/contracts/agent-protocol \
    FALAK_CONFIG_CONTRACTS_PATH=/opt/falak/contracts/config \
    FALAK_TEMPLATES_PATH=/opt/falak/templates \
    FALAK_GRAFANA_DASHBOARDS_PATH=/opt/falak/observability/grafana/dashboards
USER www-data
EXPOSE 8080
HEALTHCHECK --interval=10s --timeout=10s --start-period=60s --retries=6 CMD ["falak-healthcheck"]
ENTRYPOINT ["falak-entrypoint"]
CMD ["web"]
