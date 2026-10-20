# syntax=docker/dockerfile:1.7
# Falak shared builder (`falak-builder serve`) with the toolchains its native mode shells out to
# (git, PHP 8.4 + Composer, Node 22 with corepack pnpm/yarn, Bun). Multi-arch: linux/amd64, linux/arm64.
#
#   docker buildx build -f deploy/builder.Dockerfile --build-arg FALAK_VERSION=v1.2.3 \
#     --platform linux/amd64,linux/arm64 -t ghcr.io/falak-sh/falak-builder:v1.2.3 .
#
# Build context: the REPOSITORY ROOT. Proven in sim/builder. Docker-mode builds need a `builder`
# server (this container has no Docker daemon/BuildKit).

ARG GO_IMAGE=golang:1.27-trixie

FROM --platform=$BUILDPLATFORM ${GO_IMAGE} AS binary
ARG FALAK_VERSION=dev
ARG TARGETARCH
WORKDIR /src/agent
COPY agent/go.mod agent/go.sum ./
RUN --mount=type=cache,target=/go/pkg/mod go mod download
COPY agent/ ./
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build \
    make "bin/falak-builder-linux-${TARGETARCH}" VERSION="${FALAK_VERSION}" \
 && cp "bin/falak-builder-linux-${TARGETARCH}" /falak-builder

FROM php:8.4-cli-trixie
ARG FALAK_VERSION=dev
ARG NODE_VERSION=22.20.0
LABEL org.opencontainers.image.title="falak-builder" \
      org.opencontainers.image.description="Falak shared build worker (falak-builder serve) with PHP/Composer/Node/Bun toolchains" \
      org.opencontainers.image.version="${FALAK_VERSION}"
COPY --from=composer:2.10.3 /usr/bin/composer /usr/local/bin/composer
COPY --from=oven/bun:1.4.2 /usr/local/bin/bun /usr/local/bin/bun
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip ca-certificates curl xz-utils openssh-client \
 && install-php-extensions intl zip bcmath pcntl gd exif \
 && curl -fsSL "https://nodejs.org/dist/v${NODE_VERSION}/node-v${NODE_VERSION}-linux-$(dpkg --print-architecture | sed 's/amd64/x64/').tar.xz" \
    | tar -xJ -C /usr/local --strip-components=1 \
 && corepack enable pnpm yarn \
 && rm -rf /var/lib/apt/lists/* /tmp/*
COPY --from=binary /falak-builder /usr/local/bin/falak-builder
COPY --chmod=0755 deploy/builder/entrypoint.sh /usr/local/bin/falak-builder-entrypoint
# pnpm/yarn are corepack shims: the version comes from the project's packageManager field.
ENV FALAK_BUILDER_DIR=/var/lib/falak-builder \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COREPACK_ENABLE_DOWNLOAD_PROMPT=0 \
    COREPACK_DEFAULT_TO_LATEST=0
VOLUME ["/var/lib/falak-builder"]
ENTRYPOINT ["falak-builder-entrypoint"]
CMD ["serve"]
