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

(
  while sleep "${FALAK_EDGE_CA_POLL:-3}"; do
    publish_root
    changed=0
    sync_ca && changed=1
    sync_agent_site && changed=1
    if [ "$changed" = 1 ]; then
      if caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile --force >/dev/null 2>&1; then
        echo "falak-edge: reloaded (Fleet CA / agent API certificate changed)"
      else
        echo "falak-edge: reload failed" >&2
      fi
    fi
  done
) &

exec caddy run --config /etc/caddy/Caddyfile --adapter caddyfile
