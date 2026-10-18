#!/usr/bin/env bash
# Scripted test for deploy/compose.yml as `docker compose config` renders it: the edge's fixed address (FALAK_EDGE_IP)
# and the app trusting only it (the whole edge subnet with an older .env that lacks it); every service but the builder
# in falakcp.slice with OOM score -900; state/host writable only in control-plane, horizon and scheduler, which wait
# for host-init (no network, read-only, CHOWN/FOWNER only). Needs docker compose (skipped without it).
#   deploy/tests/compose-config.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$*"; }
if ! docker compose version >/dev/null 2>&1 || ! command -v jq >/dev/null 2>&1; then echo "skip - no docker compose / jq"; exit 0; fi
work="$(mktemp -d "${TMPDIR:-/tmp}/compose-config.XXXXXX")"
trap 'rm -rf "$work"' EXIT

render() { # render EXTRA_ENV_LINE -> $work/out.json
  printf 'FALAK_IMAGE_PREFIX=ghcr.io/x\nFALAK_VERSION=v0.23.0\nAPP_KEY=k\nFALAK_URL=https://p.test\nDB_PASSWORD=d\nREDIS_PASSWORD=r\nREVERB_APP_ID=a\nREVERB_APP_KEY=b\nREVERB_APP_SECRET=c\nFALAK_AGENT_API_URL=https://agents.p.test/agent/v1\nFALAK_AGENT_API_HOST=agents.p.test\nFALAK_BUILDER_TOKEN=t\nFALAK_DOMAIN=p.test\nFALAK_EDGE_SUBNET=10.213.77.0/24\n%s\n' "$1" > "$work/env"
  COMPOSE_PROFILES=observability docker compose -p falak --env-file "$work/env" -f "$here/../compose.yml" config --format json > "$work/out.json"
}
q() { jq -r "$1" "$work/out.json"; }

render 'FALAK_EDGE_IP=10.213.77.250'
[ "$(q '.services.edge.networks.edge.ipv4_address')" = 10.213.77.250 ] || fail "edge address: $(q '.services.edge.networks.edge')"
[ "$(q '.services["control-plane"].environment.TRUSTED_PROXIES')" = 10.213.77.250 ] || fail "TRUSTED_PROXIES"
[ "$(q '.services["agent-api"].environment.FALAK_AGENT_TRUSTED_PROXIES')" = 10.213.77.250 ] || fail "FALAK_AGENT_TRUSTED_PROXIES"
render ''
[ "$(q '.services.edge.networks.edge.ipv4_address // "none"')" = none ] || fail "an address without FALAK_EDGE_IP"
[ "$(q '.services["control-plane"].environment.TRUSTED_PROXIES')" = 10.213.77.0/24 ] || fail "fallback TRUSTED_PROXIES"
pass "the edge has FALAK_EDGE_IP on the edge network and the app trusts only it (the subnet when unset)"

bad="$(q '.services | to_entries[] | select(.key != "builder") | select(.value.cgroup_parent != "falakcp.slice" or .value.oom_score_adj != -900) | .key')"
[ -z "$bad" ] || fail "not in falakcp.slice / oom -900: $bad"
[ "$(q '.services.builder.oom_score_adj')" = 500 ] && [ "$(q '.services.builder.deploy.resources.limits.pids')" = 4096 ] || fail "builder caps"
pass "every service but the builder runs in falakcp.slice with OOM score -900; the builder is capped"

[ "$(q '[.services | to_entries[] | select([.value.volumes[]? | .target] | index("/falak/host")) | .key] | sort | join(",")')" = control-plane,horizon,scheduler ] \
  || fail "/falak/host mounted in: $(q '[.services | to_entries[] | select([.value.volumes[]? | .target] | index("/falak/host")) | .key] | join(",")')"
[ "$(q '[.services | to_entries[] | select(.value.depends_on["host-init"].condition == "service_healthy") | .key] | sort | join(",")')" = control-plane,horizon,scheduler ] \
  || fail "host-init dependants"
[ "$(q '.services["host-init"] | [.network_mode, (.read_only|tostring), (.cap_drop|join(",")), (.cap_add|join(",")), (.security_opt|join(","))] | join(" ")')" = "none true ALL CHOWN,FOWNER no-new-privileges:true" ] \
  || fail "host-init hardening: $(q '.services["host-init"]')"
pass "state/host only in control-plane/horizon/scheduler, after host-init (no network, read-only, CHOWN+FOWNER, no-new-privileges)"

[ "$(q '.services["host-init"].healthcheck | [.interval, (.start_interval // "none")] | join(" ")')" = "2s none" ] || fail "host-init healthcheck: $(q '.services["host-init"].healthcheck')"
q '.services["host-init"].entrypoint | join(" ")' | grep -q 'chown 0:33 /state/host' || fail "host-init chown (default uid 33)"
FALAK_APP_UID=1000 render ''
q '.services["host-init"].entrypoint | join(" ")' | grep -q 'chown 0:1000 /state/host' || fail "host-init ignores FALAK_APP_UID"
[ "$(q '.services.builder.environment.FALAK_BUILD_MIN_FREE_GB')" = "" ] && [ "$(q '.services.builder.environment.FALAK_BUILD_ABORT_FREE_GB')" = "" ] || fail "builder disk floors should default empty"
render $'FALAK_BUILD_MIN_FREE_GB=20\nFALAK_BUILD_ABORT_FREE_GB=4'
[ "$(q '.services.builder.environment.FALAK_BUILD_MIN_FREE_GB')" = 20 ] && [ "$(q '.services.builder.environment.FALAK_BUILD_ABORT_FREE_GB')" = 4 ] || fail "builder disk floors not passed"
pass "host-init: 2 s healthcheck without start_interval, group = FALAK_APP_UID (33); the builder gets FALAK_BUILD_MIN/ABORT_FREE_GB (empty: its defaults)"
