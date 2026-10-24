#!/usr/bin/env bash
# Scripted test for the panel allowlist in deploy/edge/Caddyfile (falak-ctl panel-access): with FALAK_PANEL_ALLOW
# set, browser paths answer 403 outside those ranges while machine paths (agents, installers, the API, the relay,
# OTLP, /up, status pages) still reach their upstreams; unset, everyone gets through. The sign-in and enrollment
# rate limits answer 429 past 30 POSTs a minute per address (GETs and other paths are not counted). Builds and runs
# the edge image (plain HTTP, no upstreams: a proxied request answers 502). Needs docker (skipped without it).
#   deploy/tests/edge-panel-access.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$*"; }
if ! docker info >/dev/null 2>&1; then echo "skip - no docker"; exit 0; fi
image="falak-edge:panel-access-test"
docker build -q -t "$image" "$here/../edge" >/dev/null

paths="/login /projects / /app/x /agent/v1/enroll /install/abc /api/v1/me /api/internal/x /api/webhooks/source-control/x /api/deploy/t /terminal/v1/x /otlp/v1/traces /up /status/acme"
probe() { # probe [FALAK_PANEL_ALLOW] -> "PATH CODE" per path, requests from 127.0.0.1
  local allow=()
  [ $# -eq 0 ] || allow=(-e "FALAK_PANEL_ALLOW=$1")
  docker run --rm -e FALAK_DOMAIN=http://p.test -e FALAK_HTTP_PORT=8080 -e FALAK_ACME_EMAIL=ops@p.test "${allow[@]}" \
    -v "$here/../edge/Caddyfile:/etc/caddy/Caddyfile:ro" --entrypoint sh "$image" -c '
      mkdir -p /etc/caddy/global /etc/caddy/sites /etc/caddy/custom
      caddy adapt --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1 || { caddy adapt --config /etc/caddy/Caddyfile --adapter caddyfile; exit 1; }
      caddy start --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1 || exit 1
      for p in '"$paths"'; do
        code="$(wget -q -S -O /dev/null --header "Host: p.test" "http://127.0.0.1:8080$p" 2>&1 | sed -n "s/^ *HTTP\/[0-9.]* \([0-9]*\).*/\1/p" | tail -1)"
        echo "$p ${code:-none}"
      done'
}
code_of() { awk -v p="$2" '$1 == p { print $2 }' <<<"$1"; }

out="$(probe)" || fail "Caddyfile without FALAK_PANEL_ALLOW: $out"
for p in $paths; do [ "$(code_of "$out" "$p")" = 502 ] || fail "without FALAK_PANEL_ALLOW $p: $(code_of "$out" "$p")"; done
pass "without FALAK_PANEL_ALLOW nothing is refused"

out="$(probe "100.64.0.0/10 fd7a:115c:a1e0::/48")" || fail "Caddyfile with the tailnet ranges: $out"
for p in /login /projects / /app/x; do [ "$(code_of "$out" "$p")" = 403 ] || fail "browser path $p from outside the tailnet: $(code_of "$out" "$p")"; done
for p in /agent/v1/enroll /install/abc /api/v1/me /api/internal/x /api/webhooks/source-control/x /api/deploy/t /terminal/v1/x /otlp/v1/traces /up /status/acme; do
  [ "$(code_of "$out" "$p")" = 502 ] || fail "machine path $p: $(code_of "$out" "$p")"
done
pass "with the tailnet ranges browser paths answer 403 from outside; machine paths still pass"

out="$(probe "127.0.0.0/8 100.64.0.0/10")" || fail "Caddyfile with loopback allowed: $out"
for p in $paths; do [ "$(code_of "$out" "$p")" = 502 ] || fail "from an allowed address $p: $(code_of "$out" "$p")"; done
pass "a client inside FALAK_PANEL_ALLOW reaches the panel"

# Rate limits: 35 POSTs to /login (the 31st on answer 429), 35 GETs of /login and then an enrollment (not limited).
out="$(docker run --rm -e FALAK_DOMAIN=http://p.test -e FALAK_HTTP_PORT=8080 -e FALAK_ACME_EMAIL=ops@p.test \
  -v "$here/../edge/Caddyfile:/etc/caddy/Caddyfile:ro" --entrypoint sh "$image" -c '
    mkdir -p /etc/caddy/global /etc/caddy/sites /etc/caddy/custom
    caddy start --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1 || exit 1
    code() { curl -s -o /dev/null -w "%{http_code}" -H "Host: p.test" "$@"; }
    i=0; while [ $i -lt 35 ]; do echo "post $(code -X POST http://127.0.0.1:8080/login)"; i=$((i + 1)); done
    i=0; while [ $i -lt 35 ]; do echo "get $(code http://127.0.0.1:8080/login)"; i=$((i + 1)); done
    echo "enroll $(code -X POST http://127.0.0.1:8080/agent/v1/enroll)"')" || fail "rate limit probe: $out"
if [ "$(grep -c '^post 502$' <<<"$out")" != 30 ] || [ "$(grep -c '^post 429$' <<<"$out")" != 5 ]; then
  fail "POST /login: $(grep '^post' <<<"$out" | sort | uniq -c | tr '\n' ' ')"
fi
[ "$(grep -c '^get 502$' <<<"$out")" = 35 ] || fail "GET /login was limited: $(grep '^get' <<<"$out" | sort | uniq -c | tr '\n' ' ')"
grep -q '^enroll 502$' <<<"$out" || fail "enrollment shares the sign-in limit: $(grep '^enroll' <<<"$out")"
pass "30 sign-in POSTs a minute per address, then 429; GETs and enrollment are counted apart"
