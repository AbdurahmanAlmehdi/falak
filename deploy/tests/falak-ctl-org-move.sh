#!/usr/bin/env bash
# Scripted test for falak-ctl's move to the falak-sh organization: ensure_org_move rewrites the old release repository
# and image registries (any case) and leaves forks and other registries alone.
#   deploy/tests/falak-ctl-org-move.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
work="$(mktemp -d "${TMPDIR:-/tmp}/falak-ctl-org-move.XXXXXX")"
trap 'rm -rf "$work"' EXIT
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$*"; }

export FALAK_DIR="$work/falak" FALAK_CTL_SOURCED=1
mkdir -p "$FALAK_DIR"
# shellcheck source=/dev/null
. "$here/../falak-ctl"

registry_serves=1
docker() { [ "$1 $2" = "manifest inspect" ] && [ "$registry_serves" = 1 ]; }

# The new registry does not serve the version yet (its packages still private): images stay, the repository follows.
printf 'FALAK_VERSION=v1.0.0\nFALAK_REPO=OthmanHaba/falak\nFALAK_IMAGE_PREFIX=ghcr.io/othmanhaba\n' > "$FALAK_DIR/.env"
registry_serves=0
ensure_org_move > "$work/out" 2>&1
[ "$(env_get FALAK_IMAGE_PREFIX)" = ghcr.io/othmanhaba ] || fail "switched to a registry that does not serve the version"
[ "$(env_get FALAK_REPO)" = falak-sh/falak ] || fail "FALAK_REPO: $(env_get FALAK_REPO)"
grep -q 'does not serve' "$work/out" || fail "no warning: $(cat "$work/out")"
pass "images stay until the new registry serves the version"
registry_serves=1
printf 'FALAK_REPO=OthmanHaba/falak\nFALAK_IMAGE_PREFIX=ghcr.io/othmanhaba\nFALAK_DB_IMAGE_REGISTRY=ghcr.io/OthmanHaba\n' > "$FALAK_DIR/.env"

ensure_org_move > "$work/out" 2>&1
[ "$(env_get FALAK_REPO)" = falak-sh/falak ] || fail "FALAK_REPO: $(env_get FALAK_REPO)"
[ "$(env_get FALAK_IMAGE_PREFIX)" = ghcr.io/falak-sh ] || fail "FALAK_IMAGE_PREFIX: $(env_get FALAK_IMAGE_PREFIX)"
[ "$(env_get FALAK_DB_IMAGE_REGISTRY)" = ghcr.io/falak-sh ] || fail "FALAK_DB_IMAGE_REGISTRY: $(env_get FALAK_DB_IMAGE_REGISTRY)"
grep -q 'moved to github.com/falak-sh' "$work/out" || fail "no notice: $(cat "$work/out")"
pass "old repository and registries follow the move"

ensure_org_move > "$work/out" 2>&1
[ ! -s "$work/out" ] || fail "second run changed something: $(cat "$work/out")"
pass "idempotent"

printf 'FALAK_REPO=acme/falak\nFALAK_IMAGE_PREFIX=registry.acme.dev/falak\n' > "$FALAK_DIR/.env"
ensure_org_move > /dev/null 2>&1
if [ "$(env_get FALAK_REPO)" != acme/falak ] || [ "$(env_get FALAK_IMAGE_PREFIX)" != registry.acme.dev/falak ]; then fail "a fork was rewritten"; fi
[ -z "$(env_get FALAK_DB_IMAGE_REGISTRY)" ] || fail "an unset key was written"
pass "forks and unset keys untouched"
