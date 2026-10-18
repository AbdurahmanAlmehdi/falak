#!/usr/bin/env bash
# End-to-end test for FALAK_LOCAL_EDGE in the edge image (deploy/edge): the image builds with caddy-l4; unset, the
# entrypoint renders no local-edge file (the config is the one without the feature); set, every host the edge serves
# itself (the panel, operator files in /etc/caddy/custom, wildcards included) stays local and any other host goes, TLS
# untouched, to a back edge with PROXY protocol v2 (the client address arrives); plain HTTP of the edge's own hosts
# still redirects to HTTPS, a new operator file is picked up without a restart, and a bad upstream refuses to start.
# Needs Docker (skipped without it); the back edge is stock caddy, as the agent's edge would be.
#   deploy/tests/edge-local-edge.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
fail() { printf 'FAIL: %s\n' "$*" >&2; dump; exit 1; }
pass() { printf 'ok - %s\n' "$*"; }
if ! command -v docker >/dev/null 2>&1 || ! docker info >/dev/null 2>&1; then echo "skip - no docker"; exit 0; fi

img="falak-edge:local-edge-test"; net="falak-le-test-$$"; p="fle$$"
work="$(mktemp -d "${TMPDIR:-/tmp}/edge-local-edge.XXXXXX")"
dump() { docker logs "$p-off" 2>&1 | tail -5 >&2 || true; docker logs "$p-front" 2>&1 | tail -20 >&2 || true; }
cleanup() { docker rm -f "$p-front" "$p-off" "$p-back" "$p-bad" >/dev/null 2>&1 || true; docker network rm "$net" >/dev/null 2>&1 || true; rm -rf "$work"; }
trap cleanup EXIT

docker build -q -t "$img" "$here/../edge" >/dev/null
docker run --rm --entrypoint caddy "$img" list-modules | grep -q '^layer4.handlers.proxy$' || fail "the image has no caddy-l4"
docker run --rm --entrypoint jq "$img" --version >/dev/null || fail "the image has no jq"
pass "the edge image builds with caddy-l4 and jq"

docker network create "$net" >/dev/null
ip_of() { docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$1"; }
curl_in() { docker run --rm --network "$net" curlimages/curl:8.11.1 -sk --max-time 5 "$@"; }
ready() { # ready CONTAINER
  local _
  for _ in $(seq 1 30); do
    docker exec "$1" curl -fsS -o /dev/null http://localhost:2019/config/ 2>/dev/null && return 0
    sleep 1
  done
  return 1
}
common=(-e FALAK_DOMAIN=panel.test -e FALAK_ACME_EMAIL=ops@panel.test -e FALAK_TLS=internal -e FALAK_EDGE_CA_POLL=1)

# --- unset: nothing rendered, no wrapper in the running config ----------------------------------------------
docker run -d --name "$p-off" --network "$net" "${common[@]}" "$img" >/dev/null
ready "$p-off" || fail "the edge did not start without FALAK_LOCAL_EDGE"
[ "$(docker exec "$p-off" ls /etc/caddy/global /etc/caddy/sites | tr '\n' ' ')" = "/etc/caddy/global: tls.caddyfile  /etc/caddy/sites: " ] \
  || fail "files rendered without FALAK_LOCAL_EDGE: $(docker exec "$p-off" ls /etc/caddy/global /etc/caddy/sites | tr '\n' ' ')"
docker exec "$p-off" curl -fsS http://localhost:2019/config/ | grep -q 'layer4\|proxy_protocol' && fail "listener wrappers without FALAK_LOCAL_EDGE"
pass "FALAK_LOCAL_EDGE unset: no local-edge file, no listener wrapper"

# --- a bad upstream refuses to start ------------------------------------------------------------------------
if docker run --name "$p-bad" "${common[@]}" -e FALAK_LOCAL_EDGE=1 -e 'FALAK_LOCAL_EDGE_UPSTREAM_HTTPS=x:1 } evil {' \
  -e FALAK_LOCAL_EDGE_UPSTREAM_HTTP=x:2 "$img" >/dev/null 2>&1; then fail "a bad upstream was accepted"; fi
pass "FALAK_LOCAL_EDGE=1 with a malformed upstream refuses to start"

# --- set: front (this image) + back (stock caddy with proxy_protocol) ------------------------------------------
mkdir -p "$work/custom"
printf 'custom.test {\n\trespond "front custom"\n}\n*.wild.test {\n\trespond "front wild"\n}\n' > "$work/custom/ops.caddyfile"
docker run -d --name "$p-front" --network "$net" "${common[@]}" -e FALAK_LOCAL_EDGE=1 \
  -e FALAK_LOCAL_EDGE_UPSTREAM_HTTPS="$p-back:8443" -e FALAK_LOCAL_EDGE_UPSTREAM_HTTP="$p-back:8080" \
  -v "$work/custom:/etc/caddy/custom:ro" "$img" >/dev/null
ready "$p-front" || fail "the edge did not start with FALAK_LOCAL_EDGE=1"
front="$(ip_of "$p-front")"
cat > "$work/back.caddyfile" <<'CONF'
{
	local_certs
	skip_install_trust
	http_port 8080
	https_port 8443
	servers {
		protocols h1 h2
		listener_wrappers {
			proxy_protocol {
				allow {$FRONT_IP}/32
				fallback_policy reject
			}
			tls
		}
	}
}
site.test {
	respond "site {remote_host}"
}
http://plain.test {
	respond "plain {remote_host}"
}
CONF
docker run -d --name "$p-back" --network "$net" -e FRONT_IP="$front" -v "$work/back.caddyfile:/etc/caddy/Caddyfile:ro" caddy:2.11.4-alpine >/dev/null
sleep 3

keep="$(docker exec "$p-front" cat /etc/caddy/global/local-edge.caddyfile)"
for h in panel.test custom.test '*.wild.test'; do
  grep -qF " $h " <<<"$(grep 'not tls sni' <<<"$keep") " || fail "keep-list lacks $h: $keep"
done
docker exec "$p-front" grep -q '^http:// {' /etc/caddy/sites/local-edge.caddyfile || fail "no explicit http:// site"
pass "keep-list = the panel, operator hosts and wildcards"

client="$(docker run --rm --network "$net" curlimages/curl:8.11.1 -sk --max-time 5 --resolve "site.test:443:$front" https://site.test/ || true)"
case "$client" in "site "[0-9]*) ;; *) fail "site.test via the front: '$client'" ;; esac
[ "${client#site }" != "$front" ] || fail "the back edge saw the front's address, not the client's"
pass "TLS of another host reaches the back edge with the client address (PROXY v2): $client"
got="$(curl_in --resolve "custom.test:443:$front" https://custom.test/ || true)"; [ "$got" = "front custom" ] || fail "custom.test: '$got'"
got="$(curl_in --resolve "a.wild.test:443:$front" https://a.wild.test/ || true)"; [ "$got" = "front wild" ] || fail "a.wild.test: '$got'"
code="$(curl_in -o /dev/null -w '%{http_code}' --resolve "panel.test:443:$front" https://panel.test/ || true)"
[ "$code" = 502 ] || fail "panel.test should be the front's panel route (no control plane here: 502), got $code"
pass "the edge's own hosts stay local (panel, operator file, wildcard)"

out="$(curl_in --resolve "plain.test:80:$front" http://plain.test/)"
case "$out" in "plain "[0-9]*) [ "${out#plain }" != "$front" ] || fail "plain.test: the front's address" ;; *) fail "plain.test over HTTP: '$out'" ;; esac
for h in panel.test custom.test; do
  loc="$(curl_in -o /dev/null -w '%{http_code} %{redirect_url}' --resolve "$h:80:$front" "http://$h/x?y=1")"
  [ "$loc" = "308 https://$h/x?y=1" ] || fail "http://$h: $loc"
done
pass "plain HTTP: other hosts reach the back edge, the edge's own hosts redirect to HTTPS (308)"

printf 'later.test {\n\trespond "front later"\n}\n' > "$work/custom/later.caddyfile"
for _ in $(seq 1 15); do
  [ "$(curl_in --resolve "later.test:443:$front" https://later.test/ 2>/dev/null || true)" = "front later" ] && break
  sleep 1
done
[ "$(curl_in --resolve "later.test:443:$front" https://later.test/)" = "front later" ] || fail "a new operator file was not kept"
pass "a new operator site joins the keep-list without a restart"
