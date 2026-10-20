#!/usr/bin/env bash
# Print a throwaway .env for validating deploy/compose.yml (CI, local tests). Never use it in production:
# install.sh generates the real one.
#   deploy/tools/test-env.sh [domain] [image-prefix] [version] > /tmp/falak.env
set -euo pipefail
domain="${1:-falak.example.com}"; prefix="${2:-ghcr.io/owner}"; version="${3:-v0.0.0-test}"
hex() { od -An -tx1 -N"$1" /dev/urandom | tr -d ' \n'; }
cat <<ENV
APP_KEY=base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')
DB_PASSWORD=$(hex 24)
REDIS_PASSWORD=$(hex 24)
REVERB_APP_ID=falak
REVERB_APP_KEY=$(hex 10)
REVERB_APP_SECRET=$(hex 20)
FALAK_BUILDER_TOKEN=kbt_$(hex 24)
FALAK_OTLP_TOKEN=$(hex 24)
GRAFANA_ADMIN_PASSWORD=$(hex 16)
FALAK_REPO=falak-sh/falak
FALAK_IMAGE_PREFIX=$prefix
FALAK_VERSION=$version
FALAK_DOMAIN=$domain
FALAK_URL=https://$domain
FALAK_AGENT_API_HOST=agents.$domain
FALAK_AGENT_API_URL=https://agents.$domain/agent/v1
FALAK_ACME_EMAIL=ops@example.com
FALAK_TLS=acme
FALAK_HTTP_PORT=80
FALAK_HTTPS_PORT=443
FALAK_EDGE_SUBNET=10.213.77.0/24
ENV
