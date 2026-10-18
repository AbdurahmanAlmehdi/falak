#!/bin/sh
# Falak edge entrypoint (production counterpart of sim/edge/entrypoint.sh).
#
#  - renders the global TLS options (FALAK_TLS=acme|internal, FALAK_ACME_CA), the optional Grafana site and the
#    built-in registry site (FALAK_REGISTRY_HOST, basic auth from FALAK_REGISTRY_USERNAME/PASSWORD);
#  - keeps /etc/caddy/trust/ca.pem in sync with the Fleet CA written by the control plane
#    ($FALAK_EDGE_CA_FILE, default /falak/ca/ca.pem). Until it exists a throwaway placeholder CA (key
#    discarded) is trusted, so every non-enroll agent call fails closed;
#  - serves $FALAK_AGENT_API_HOST with the Fleet-issued server certificate (agent-api.{pem,key}) once present;
#  - publishes Caddy's internal root to $FALAK_EDGE_PKI_OUT (FALAK_TLS=internal: the builder trusts it);
#  - FALAK_LOCAL_EDGE=1 (falak-ctl local-server enable): layer4 listener wrappers on the HTTP/HTTPS servers hand every
#    host this edge does not serve itself to the control-plane machine's own agent edge (PROXY protocol v2);
#  - hot-reloads Caddy whenever any of that changes.
set -eu

: "${FALAK_DOMAIN:?FALAK_DOMAIN is required}"
SRC="${FALAK_EDGE_CA_FILE:-/falak/ca/ca.pem}"
DST=/etc/caddy/trust/ca.pem
PKI_OUT="${FALAK_EDGE_PKI_OUT:-}"
ROOT=/data/caddy/pki/authorities/local/root.crt
AGENT_HOST="${FALAK_AGENT_API_HOST:-agents.$FALAK_DOMAIN}"
AGENT_CERT="$(dirname "$SRC")/agent-api.pem"
AGENT_KEY="$(dirname "$SRC")/agent-api.key"
AGENT_TLS=/etc/caddy/agent-tls
SITE=/etc/caddy/sites/agent-api.caddyfile
export FALAK_AGENT_API_HOST="$AGENT_HOST"
mkdir -p "$AGENT_TLS" /etc/caddy/sites /etc/caddy/global /etc/caddy/trust

# --- static render (once per container start) ---------------------------------------------------------
{
  case "${FALAK_TLS:-acme}" in
    internal)
      echo "local_certs"
      echo "skip_install_trust"
      ;;
    acme)
      if [ -n "${FALAK_ACME_CA:-}" ]; then echo "acme_ca ${FALAK_ACME_CA}"; fi
      ;;
    *) echo "falak-edge: FALAK_TLS must be acme or internal" >&2; exit 2 ;;
  esac
} > /etc/caddy/global/tls.caddyfile

rm -f /etc/caddy/sites/grafana.caddyfile /etc/caddy/sites/aliases.caddyfile /etc/caddy/sites/registry.caddyfile
# Former panel domains (falak-ctl domain set --keep-old) redirect to the current one. 308, not 301: a POST to an old
# domain keeps its method when the client follows the redirect (a 301 may turn it into a GET).
if [ -n "${FALAK_DOMAIN_ALIASES:-}" ]; then
  cat > /etc/caddy/sites/aliases.caddyfile <<SITE
$(printf '%s' "$FALAK_DOMAIN_ALIASES" | sed 's/,/, /g') {
	redir ${FALAK_URL:-https://$FALAK_DOMAIN}{uri} 308
}
SITE
fi
if [ -n "${FALAK_GRAFANA_HOST:-}" ]; then
  cat > /etc/caddy/sites/grafana.caddyfile <<SITE
${FALAK_GRAFANA_HOST} {
	import security_headers
	log
	reverse_proxy grafana:3000
}
SITE
fi

# valid_hosts LIST: every comma-separated entry is a DNS name (letters, digits, - and . between labels; no port,
# wildcard or IP literal), so the value can't add Caddy tokens or site blocks to the rendered Caddyfile.
valid_hosts() {
  [ -n "$1" ] || return 1
  # Checked whole first: no spaces or glob characters reach the word splitting below.
  case "$1" in *[!A-Za-z0-9.,-]*|,*|*,|*,,*) return 1 ;; esac
  for h in $(printf '%s' "$1" | tr ',' ' '); do
    case "$h" in
      .*|*.|*..*|-*|*-|*.-*|*-.*) return 1 ;;
    esac
    [ "${#h}" -le 253 ] || return 1
  done
  return 0
}

# Built-in image registry: never served without credentials (the registry itself has no auth). Former hosts
# (falak-ctl domain set --keep-old) keep working, so images referenced by old releases still pull.
if [ -n "${FALAK_REGISTRY_HOST:-}" ] && [ -n "${FALAK_REGISTRY_USERNAME:-}" ] && [ -n "${FALAK_REGISTRY_PASSWORD:-}" ]; then
  case "$FALAK_REGISTRY_USERNAME" in *[!A-Za-z0-9_.-]*) echo "falak-edge: FALAK_REGISTRY_USERNAME may only use letters, digits, _ . -" >&2; exit 2 ;; esac
  if ! valid_hosts "$FALAK_REGISTRY_HOST" || [ "${FALAK_REGISTRY_HOST#*,}" != "$FALAK_REGISTRY_HOST" ]; then
    echo "falak-edge: FALAK_REGISTRY_HOST must be one host name (letters, digits, - and .)" >&2; exit 2
  fi
  if [ -n "${FALAK_REGISTRY_HOST_ALIASES:-}" ] && ! valid_hosts "$FALAK_REGISTRY_HOST_ALIASES"; then
    echo "falak-edge: FALAK_REGISTRY_HOST_ALIASES must be comma-separated host names (letters, digits, - and .)" >&2; exit 2
  fi
  REGISTRY_HASH="$(caddy hash-password --plaintext "$FALAK_REGISTRY_PASSWORD")"
  cat > /etc/caddy/sites/registry.caddyfile <<SITE
${FALAK_REGISTRY_HOST}$(printf '%s' "${FALAK_REGISTRY_HOST_ALIASES:-}" | sed 's/^/,/; s/,/, /g; s/^, $//') {
	log
	header Docker-Distribution-Api-Version registry/2.0
	basic_auth {
		${FALAK_REGISTRY_USERNAME} ${REGISTRY_HASH}
	}
	# Image layers: no request body limit, stream both ways.
	reverse_proxy registry:5000 {
		flush_interval -1
		transport http {
			read_timeout 30m
			write_timeout 30m
		}
	}
}
SITE
  unset REGISTRY_HASH
fi

# --- local edge: sites on the control-plane machine ------------------------------------------------------
# The agent on this machine runs its own edge on FALAK_LOCAL_EDGE_UPSTREAM_HTTPS / _HTTP (the edge network's gateway).
# Keep-list = every host the config without these files serves (from `caddy adapt`, operator files included);
# TLS (by SNI) and plain HTTP (by Host) of any other host is passed through untouched, with the client address in a
# PROXY v2 header. HTTP/3 (udp) stays with this edge and its own hosts. Unset: the files are absent and the config
# is exactly the one without the feature.
LOCAL_EDGE_GLOBAL=/etc/caddy/global/local-edge.caddyfile
LOCAL_EDGE_SITE=/etc/caddy/sites/local-edge.caddyfile
LOCAL_EDGE_SUM=""

# valid_upstream HOST:PORT : an IPv4 address or DNS name and a port, nothing Caddy could parse as more.
valid_upstream() {
  case "$1" in *[!A-Za-z0-9.:-]*|:*|*:|*:*:*|'') return 1 ;; esac
  case "$1" in *:*) ;; *) return 1 ;; esac
  case "${1##*:}" in *[!0-9]*) return 1 ;; esac
  return 0
}

if [ "${FALAK_LOCAL_EDGE:-}" = 1 ]; then
  for v in "${FALAK_LOCAL_EDGE_UPSTREAM_HTTPS:-}" "${FALAK_LOCAL_EDGE_UPSTREAM_HTTP:-}"; do
    valid_upstream "$v" || { echo "falak-edge: FALAK_LOCAL_EDGE=1 needs FALAK_LOCAL_EDGE_UPSTREAM_HTTPS and _HTTP as host:port" >&2; exit 2; }
  done
fi

local_edge_inputs() { # checksum of everything the keep-list comes from
  for f in /etc/caddy/custom/*.caddyfile /etc/caddy/sites/*.caddyfile; do
    [ "$f" = "$LOCAL_EDGE_SITE" ] || cat "$f" 2>/dev/null || true
  done | md5sum
}

render_local_edge() { # 0 when the files changed
  if [ "${FALAK_LOCAL_EDGE:-}" != 1 ]; then
    [ -e "$LOCAL_EDGE_GLOBAL" ] || [ -e "$LOCAL_EDGE_SITE" ] || return 1
    rm -f "$LOCAL_EDGE_GLOBAL" "$LOCAL_EDGE_SITE"; return 0
  fi
  sum="$(local_edge_inputs)"
  [ "$sum" != "$LOCAL_EDGE_SUM" ] || return 1
  LOCAL_EDGE_SUM="$sum"
  had=0; [ ! -e "$LOCAL_EDGE_GLOBAL" ] || had=1
  rm -f "$LOCAL_EDGE_GLOBAL" "$LOCAL_EDGE_SITE"
  if ! json="$(caddy adapt --config /etc/caddy/Caddyfile --adapter caddyfile 2>/dev/null)"; then
    LOCAL_EDGE_SUM=""   # retried at the next poll
    echo "falak-edge: local edge: caddy adapt failed, not passing other hosts through" >&2
    [ "$had" = 1 ]; return
  fi
  hosts=""
  set -f   # *.example.com is a host, not a glob
  # The edge's own hosts always stay, whatever adapt reports; then every host matcher of the config. Matching is
  # case-insensitive in Caddy, the keep-list is lowercase. (valid_hosts loops over $h: another name here.)
  for kh in $( {
    printf '%s\n' "$FALAK_DOMAIN" "${FALAK_DOMAIN_ALIASES:-}" "$AGENT_HOST" "${FALAK_AGENT_API_HOST_ALIASES:-}" \
      "${FALAK_REGISTRY_HOST:-}" "${FALAK_REGISTRY_HOST_ALIASES:-}" "${FALAK_GRAFANA_HOST:-}" | tr ',' '\n'
    printf '%s' "$json" | jq -r '.. | objects | select(has("host")) | .host | arrays | .[] | strings'
  } | tr '[:upper:]' '[:lower:]' | sort -u ); do
    # Wildcards (*.example.com) work in both matchers.
    if valid_hosts "${kh#\*.}"; then hosts="$hosts $kh"; else echo "falak-edge: local edge: not keeping host '$kh' (not a DNS name)" >&2; fi
  done
  set +f
  [ -n "$hosts" ] || hosts=" $FALAK_DOMAIN"
  https_port="${FALAK_HTTPS_PORT:-443}"; redir_port=""; [ "$https_port" = 443 ] || redir_port=":$https_port"
  cat > "$LOCAL_EDGE_GLOBAL" <<CONF
servers :$https_port {
	listener_wrappers {
		layer4 {
			@falak_local not tls sni$hosts
			route @falak_local {
				proxy {
					proxy_protocol v2
					upstream $FALAK_LOCAL_EDGE_UPSTREAM_HTTPS
				}
			}
		}
		tls
	}
}
servers :${FALAK_HTTP_PORT:-80} {
	listener_wrappers {
		layer4 {
			@falak_local not http host$hosts
			route @falak_local {
				proxy {
					proxy_protocol v2
					upstream $FALAK_LOCAL_EDGE_UPSTREAM_HTTP
				}
			}
		}
	}
}
CONF
  # An explicit HTTP server: Caddy's implicit redirect server takes no `servers` options (no listener wrapper).
  cat > "$LOCAL_EDGE_SITE" <<CONF
http:// {
	redir https://{host}$redir_port{uri} 308
}
CONF
  echo "falak-edge: local edge: keeping$hosts; every other host goes to $FALAK_LOCAL_EDGE_UPSTREAM_HTTPS / $FALAK_LOCAL_EDGE_UPSTREAM_HTTP"
  return 0
}

# --- dynamic: Fleet CA + agent API certificate ---------------------------------------------------------
placeholder() {
  tmp=$(mktemp -d)
  openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -days 3650 \
    -subj "/CN=Falak placeholder CA (no Fleet CA yet)" \
    -keyout "$tmp/key.pem" -out "$DST" >/dev/null 2>&1
  rm -rf "$tmp"
  echo "falak-edge: Fleet CA not found at $SRC yet; using a placeholder (agent mTLS rejects everything)"
}

sync_ca() {
  if [ -s "$SRC" ] && openssl x509 -in "$SRC" -noout >/dev/null 2>&1; then
    if ! cmp -s "$SRC" "$DST"; then
      cp "$SRC" "$DST.tmp" && mv "$DST.tmp" "$DST"
      echo "falak-edge: trusting Fleet CA $(openssl x509 -in "$DST" -noout -subject -fingerprint -sha256 | tr '\n' ' ')"
      return 0
    fi
    return 1
  fi
  [ -s "$DST" ] || { placeholder; return 0; }
  return 1
}

pem_normalise() {
  awk '{ gsub(/\r/, ""); gsub(/-----END ([A-Z ]+)-----/, "&\n"); gsub(/\n$/, ""); print }' | awk 'NF'
}

sync_agent_site() {
  if [ -s "$AGENT_CERT" ] && [ -s "$AGENT_KEY" ]; then
    if [ ! -f "$SITE" ] || ! cmp -s "$AGENT_CERT" "$AGENT_TLS/src.pem" || ! cmp -s "$AGENT_KEY" "$AGENT_TLS/src.key"; then
      cp "$AGENT_CERT" "$AGENT_TLS/src.pem" && cp "$AGENT_KEY" "$AGENT_TLS/src.key"
      pem_normalise < "$AGENT_CERT" > "$AGENT_TLS/agent-api.pem"
      pem_normalise < "$AGENT_KEY" > "$AGENT_TLS/agent-api.key"
      chmod 600 "$AGENT_TLS/agent-api.key"
      cat > "$SITE" <<SITE
$AGENT_HOST$(printf '%s' "${FALAK_AGENT_API_HOST_ALIASES:-}" | sed 's/^/,/; s/,/, /g; s/^, $//') {
	tls $AGENT_TLS/agent-api.pem $AGENT_TLS/agent-api.key {
		import client_auth_fleet
	}
	log
	import falak_routes
}
SITE
      echo "falak-edge: serving the agent API on $AGENT_HOST with the Fleet-issued server certificate"
      return 0
    fi
    return 1
  fi
  if [ -f "$SITE" ]; then rm -f "$SITE"; return 0; fi
  return 1
}

publish_root() {
  if [ -n "$PKI_OUT" ] && [ -s "$ROOT" ] && [ -d "$PKI_OUT" ] && ! cmp -s "$ROOT" "$PKI_OUT/root.crt"; then
    cp "$ROOT" "$PKI_OUT/root.crt.tmp" && chmod 644 "$PKI_OUT/root.crt.tmp" && mv "$PKI_OUT/root.crt.tmp" "$PKI_OUT/root.crt"
    echo "falak-edge: published the internal TLS root CA to $PKI_OUT/root.crt"
  fi
}

sync_ca || true
sync_agent_site || true
render_local_edge || true

(
  while sleep "${FALAK_EDGE_CA_POLL:-3}"; do
    publish_root
    changed=0
    sync_ca && changed=1
    sync_agent_site && changed=1
    render_local_edge && changed=1
    if [ "$changed" = 1 ]; then
      if caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile --force >/dev/null 2>&1; then
        echo "falak-edge: reloaded (Fleet CA / agent API certificate / local edge hosts changed)"
      else
        echo "falak-edge: reload failed" >&2
      fi
    fi
  done
) &

exec caddy run --config /etc/caddy/Caddyfile --adapter caddyfile
