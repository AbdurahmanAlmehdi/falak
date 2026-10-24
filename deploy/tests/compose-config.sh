#!/usr/bin/env bash
# Scripted test for deploy/compose.yml as `docker compose config` renders it: the edge's fixed address (FALAK_EDGE_IP)
# and the app trusting only it (the whole edge subnet with an older .env that lacks it); every service but the builder
# in falakcp.slice with OOM score -900; state/host writable only in control-plane, horizon and scheduler, which wait
# for host-init (no network, read-only, CHOWN/FOWNER/DAC_READ_SEARCH only; Tempo waits for it too); the stateful,
# edge, builder and observability services drop every capability but the few they add, with no-new-privileges; the edge's FALAK_PANEL_ALLOW (everyone unless set). Needs docker compose (skipped without it).
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
if ! { [ "$(q '.services.builder.oom_score_adj')" = 500 ] && [ "$(q '.services.builder.deploy.resources.limits.pids')" = 4096 ]; }; then fail "builder caps"; fi
pass "every service but the builder runs in falakcp.slice with OOM score -900; the builder is capped"

[ "$(q '[.services | to_entries[] | select([.value.volumes[]? | .target] | index("/falak/host")) | .key] | sort | join(",")')" = control-plane,horizon,scheduler ] \
  || fail "/falak/host mounted in: $(q '[.services | to_entries[] | select([.value.volumes[]? | .target] | index("/falak/host")) | .key] | join(",")')"
[ "$(q '[.services | to_entries[] | select(.value.depends_on["host-init"].condition == "service_healthy") | .key] | sort | join(",")')" = control-plane,horizon,scheduler,tempo ] \
  || fail "host-init dependants"
[ "$(q '.services["host-init"] | [.network_mode, (.read_only|tostring), (.cap_drop|join(",")), (.cap_add|join(",")), (.security_opt|join(","))] | join(" ")')" = "none true ALL CHOWN,FOWNER,DAC_READ_SEARCH no-new-privileges:true" ] \
  || fail "host-init hardening: $(q '.services["host-init"]')"
pass "state/host only in control-plane/horizon/scheduler, after host-init (no network, read-only, CHOWN+FOWNER+DAC_READ_SEARCH, no-new-privileges)"
caps() { q ".services[\"$1\"] | [(.cap_drop // [] | join(\",\")), (.cap_add // [] | sort | join(\",\")), (.security_opt // [] | join(\",\"))] | join(\" \")"; }
for want in "postgres ALL CHOWN,DAC_OVERRIDE,FOWNER,SETGID,SETUID" "valkey ALL " "registry ALL " "edge ALL DAC_READ_SEARCH,NET_BIND_SERVICE" \
  "builder ALL CHOWN,DAC_OVERRIDE,FOWNER,FSETID,KILL,SETGID,SETUID" "gateway ALL NET_BIND_SERVICE" "loki ALL " "tempo ALL " "victoriametrics ALL " "grafana ALL "; do
  svc="${want%% *}"
  [ "$(caps "$svc")" = "${want#* } no-new-privileges:true" ] || fail "$svc capabilities: $(caps "$svc")"
done
[ "$(q '.services.tempo.user // "image"')" = image ] || fail "tempo runs as $(q '.services.tempo.user')"
[ "$(q '[.services.registry.read_only, .services.gateway.read_only] | map(tostring) | join(" ")')" = "true true" ] || fail "registry/gateway read-only"
pass "postgres, valkey, registry, edge, builder and the observability services: cap_drop ALL + their few caps, no-new-privileges; Tempo as its own uid"

[ "$(q '.services["host-init"].healthcheck | [.interval, (.start_interval // "none")] | join(" ")')" = "2s none" ] || fail "host-init healthcheck: $(q '.services["host-init"].healthcheck')"
q '.services["host-init"].entrypoint | join(" ")' | grep -q 'chown 0:33 /state/host' || fail "host-init chown (default uid 33)"
FALAK_APP_UID=1000 render ''
q '.services["host-init"].entrypoint | join(" ")' | grep -q 'chown 0:1000 /state/host' || fail "host-init ignores FALAK_APP_UID"
if ! { [ "$(q '.services.builder.environment.FALAK_BUILD_MIN_FREE_GB')" = "" ] && [ "$(q '.services.builder.environment.FALAK_BUILD_ABORT_FREE_GB')" = "" ]; }; then fail "builder disk floors should default empty"; fi
render $'FALAK_BUILD_MIN_FREE_GB=20\nFALAK_BUILD_ABORT_FREE_GB=4'
if ! { [ "$(q '.services.builder.environment.FALAK_BUILD_MIN_FREE_GB')" = 20 ] && [ "$(q '.services.builder.environment.FALAK_BUILD_ABORT_FREE_GB')" = 4 ]; }; then fail "builder disk floors not passed"; fi
pass "host-init: 2 s healthcheck without start_interval, group = FALAK_APP_UID (33); the builder gets FALAK_BUILD_MIN/ABORT_FREE_GB (empty: its defaults)"

for line in '' 'FALAK_PANEL_ALLOW='; do
  render "$line"
  [ "$(q '.services.edge.environment.FALAK_PANEL_ALLOW')" = "0.0.0.0/0 ::/0" ] || fail "FALAK_PANEL_ALLOW default ($line): $(q '.services.edge.environment.FALAK_PANEL_ALLOW')"
done
render 'FALAK_PANEL_ALLOW=100.64.0.0/10 fd7a:115c:a1e0::/48'
[ "$(q '.services.edge.environment.FALAK_PANEL_ALLOW')" = "100.64.0.0/10 fd7a:115c:a1e0::/48" ] || fail "FALAK_PANEL_ALLOW: $(q '.services.edge.environment.FALAK_PANEL_ALLOW')"
pass "the edge gets FALAK_PANEL_ALLOW (unset or empty: everyone)"

render 'FALAK_REGISTRY_USERNAME=falak
FALAK_REGISTRY_PASSWORD=operator-secret'
[ "$(q '.services.registry.environment.REGISTRY_AUTH')" = token ] || fail "registry without token auth"
[ "$(q '.services.registry.environment.REGISTRY_AUTH_TOKEN_REALM')" = https://p.test/api/internal/registry/token ] || fail "realm: $(q '.services.registry.environment.REGISTRY_AUTH_TOKEN_REALM')"
[ "$(q '.services.registry.volumes[] | select(.target == "/etc/falak/registry-token.crt") | .read_only')" = true ] || fail "token certificate not mounted read-only"
if q '.services.edge.environment' | grep -q operator-secret; then fail "the edge gets the registry operator credential"; fi
pass "the registry checks tokens from the control plane's realm; the edge no longer holds the registry credential"
# /v2/ answers 401 under token auth, so a health check there never passes (v1.3.0 update rolled back on it).
q '.services.registry.healthcheck.test | join(" ")' | grep -q '/v2/' && fail "registry health check probes /v2/ (401 with token auth)"
q '.services.registry.healthcheck.test | join(" ")' | grep -q "127.0.0.1:5001/debug/health" || fail "registry health check: $(q '.services.registry.healthcheck.test | join(" ")')"
[ "$(q '.services.registry.environment.REGISTRY_HTTP_DEBUG_ADDR')" = 127.0.0.1:5001 ] || fail "registry debug listener not on loopback"
pass "the registry health check uses the loopback debug listener, not /v2/"
