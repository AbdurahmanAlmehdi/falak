#!/usr/bin/env bash
# Falak installer — single-host Docker Compose install into /opt/falak.
#
#   curl -fsSL https://falak.sh/install.sh \
#     | sudo bash -s -- --domain falak.example.com --email you@example.com
#
# Options (each also settable via the environment variable in brackets):
#   --domain NAME            panel domain; agents use agents.NAME                           [FALAK_DOMAIN]
#   --email ADDRESS          Let's Encrypt account + first admin e-mail                      [FALAK_EMAIL]
#   --admin-email ADDRESS    first admin (default: --email)                                  [FALAK_ADMIN_EMAIL]
#   --version TAG            release to install (default: latest release)                    [FALAK_VERSION]
#   --observability          also run Grafana/Loki/Tempo/VictoriaMetrics (grafana.NAME)      [FALAK_OBSERVABILITY=1]
#   --tls acme|internal      internal = Caddy's local CA, for testing only (default: acme)    [FALAK_TLS]
#   --allow-local-server     let the panel make this machine a server (it then acts as root here) [FALAK_ALLOW_LOCAL_SERVER=1]
#   --repo OWNER/NAME        GitHub repository of the release (default: falak-sh/falak)         [FALAK_REPO]
#   --image-prefix PREFIX    image registry prefix (default: ghcr.io/<owner>)                [FALAK_IMAGE_PREFIX]
#   --build-from-source      build images locally from --ref instead of pulling them         [FALAK_BUILD_FROM_SOURCE=1]
#   --ref REF                git ref for --build-from-source (default: --version or main)    [FALAK_REF]
#   --source-dir PATH        use deploy files from a local checkout (offline/testing)       [FALAK_DEPLOY_SOURCE]
#   --http-port N / --https-port N   non-standard ports (testing; ACME needs 80/443)        [FALAK_HTTP_PORT/FALAK_HTTPS_PORT]
#   --dir PATH               install directory (default: /opt/falak)                          [FALAK_DIR]
#   --skip-dns-check         don't require DNS to point at this host                        [FALAK_SKIP_DNS_CHECK=1]
#   --restore-from s3://latest|s3://NAME   a lost control plane: restore its backup from the DR bucket instead of
#                            starting empty (same CA and KEK: agents reconnect). Bucket settings and the DR
#                            passphrase from FALAK_BACKUP_S3_* / FALAK_BACKUP_PASSPHRASE, or asked  [FALAK_RESTORE_FROM]
#   --force                  continue on unsupported OS / low resources
#
# Re-running is safe: secrets in /opt/falak/.env are kept, settings given on the command line are updated,
# the stack is converged, and the first admin is only created once.
set -euo pipefail

FALAK_DIR="${FALAK_DIR:-/opt/falak}"
FALAK_PROJECT="${FALAK_PROJECT:-falak}"
DOMAIN="${FALAK_DOMAIN:-}"
EMAIL="${FALAK_EMAIL:-}"
ADMIN_EMAIL="${FALAK_ADMIN_EMAIL:-}"
VERSION="${FALAK_VERSION:-}"
OBSERVABILITY="${FALAK_OBSERVABILITY:-}"
ALLOW_LOCAL_SERVER="${FALAK_ALLOW_LOCAL_SERVER:-}"
TLS="${FALAK_TLS:-}"
REPO="${FALAK_REPO:-}"
IMAGE_PREFIX="${FALAK_IMAGE_PREFIX:-}"
FROM_SOURCE="${FALAK_BUILD_FROM_SOURCE:-0}"
REF="${FALAK_REF:-}"
SOURCE_DIR="${FALAK_DEPLOY_SOURCE:-}"
HTTP_PORT="${FALAK_HTTP_PORT:-}"
HTTPS_PORT="${FALAK_HTTPS_PORT:-}"
SKIP_DNS="${FALAK_SKIP_DNS_CHECK:-0}"
RESTORE_FROM="${FALAK_RESTORE_FROM:-}"
FORCE=0
DEFAULT_REPO="falak-sh/falak"

if [ -t 1 ]; then B=$'\e[1m'; R=$'\e[31m'; G=$'\e[32m'; Y=$'\e[33m'; N=$'\e[0m'; else B=; R=; G=; Y=; N=; fi
info() { printf '%s==>%s %s\n' "$B" "$N" "$*"; }
ok()   { printf '  %s✓%s %s\n' "$G" "$N" "$*"; }
warn() { printf '  %s!%s %s\n' "$Y" "$N" "$*" >&2; }
die()  { printf '\n%serror:%s %s\n' "$R" "$N" "$*" >&2; exit 1; }

force_or_die() { if [ "$FORCE" = 1 ]; then warn "$1 (continuing: --force)"; else die "$2"; fi; }
os_field() { sed -n "s/^$1=//p" "${FALAK_OS_RELEASE:-/etc/os-release}" 2>/dev/null | tr -d '"' | head -1; }
SUPPORTED_OS="Ubuntu 22.04/24.04/26.04 and Debian 12"
os_supported() { case "$1:$2" in ubuntu:22.04|ubuntu:24.04|ubuntu:26.04|debian:12) return 0 ;; *) return 1 ;; esac; }

# pull_attempts VALUE : VALUE when it is a positive integer, else 4 (with a warning).
pull_attempts() {
  case "$1" in
    '' | *[!0-9]*) ;;
    *) if [ "$((10#$1))" -ge 1 ]; then echo "$((10#$1))"; return 0; fi ;;
  esac
  warn "FALAK_PULL_ATTEMPTS='$1' is not a positive number; using 4"
  echo 4
}

# pull_permanent FILE : the pull's error output is a registry refusal no retry fixes: no access, or no such image or
# tag. Registry phrases only — a network error that merely says "not found" (a DNS lookup) stays transient. Kept
# identical in deploy/install.sh and deploy/falak-ctl (deploy/tests/pull-retry.sh checks it).
pull_permanent() {
  grep -qiE 'manifest unknown|manifest (for )?[^ ]+ not found|pull access denied|requested access to the resource is denied|unauthorized:|authentication required|": denied(:|$)' "$1"
}

# pull_retry CMD... : run an image pull, retrying transient registry errors ("connection reset by peer", IPv6
# resets, 5xx) with backoff: FALAK_PULL_ATTEMPTS tries (default 4), waiting 5s, 10s, 20s ... in between. Errors no
# retry can fix (access denied, unknown manifest or tag) fail at once.
pull_retry() {
  local attempt=1 max delay="${FALAK_PULL_DELAY:-5}" err
  max="$(pull_attempts "${FALAK_PULL_ATTEMPTS:-4}")"
  err="$(mktemp "${TMPDIR:-/tmp}/.falak-pull.XXXXXX")"
  until "$@" 2>"$err"; do
    cat "$err" >&2
    if pull_permanent "$err"; then
      rm -f "$err"
      warn "image pull failed: the registry refused it (access denied, or no such image or tag); not retrying"
      return 1
    fi
    if [ "$attempt" -ge "$max" ]; then
      rm -f "$err"
      warn "image pull failed $attempt times, giving up"
      return 1
    fi
    warn "image pull failed (attempt $attempt of $max, usually a transient registry or network error); retrying in ${delay}s"
    sleep "$delay"
    attempt=$((attempt + 1)); delay=$((delay * 2))
  done
  cat "$err" >&2
  rm -f "$err"
}

# RELEASE_PUBKEY: the Ed25519 release signing key (base64 SPKI, the body of agent/internal/cli/release-signing.pub),
# pinned in by deploy/tools/release-assets.sh. Empty in a source checkout: SHA256SUMS is then trusted unsigned.
RELEASE_PUBKEY=""

# verify_sums_sig DIR BASE_URL : DIR/SHA256SUMS must carry a valid BASE_URL/SHA256SUMS.sig once a key is pinned;
# anything else (no signature, a bad one, no OpenSSL 3) is fatal. Kept identical in deploy/install.sh and
# deploy/falak-ctl (deploy/tests/release-signing.sh checks it).
verify_sums_sig() {
  if [ -z "$RELEASE_PUBKEY" ]; then
    warn "no release signing key in this script: SHA256SUMS is checked unsigned"
    return 0
  fi
  command -v openssl >/dev/null 2>&1 || die "openssl is required to check the release signature (apt-get install openssl)"
  curl -fsSL --retry 3 -o "$1/SHA256SUMS.sig" "$2/SHA256SUMS.sig" \
    || die "could not download $2/SHA256SUMS.sig: refusing an unsigned release"
  printf '%s\n' "-----BEGIN PUBLIC KEY-----" "$RELEASE_PUBKEY" "-----END PUBLIC KEY-----" > "$1/release-signing.pub"
  openssl pkeyutl -verify -rawin -pubin -inkey "$1/release-signing.pub" -in "$1/SHA256SUMS" -sigfile "$1/SHA256SUMS.sig" >/dev/null 2>&1 \
    || die "SHA256SUMS does not match its signature (SHA256SUMS.sig, needs OpenSSL 3): refusing this release"
}

# verify_image_digests FILE PREFIX VERSION : the Falak images PREFIX/falak-*:VERSION on this host are the ones the
# release recorded in FILE (deploy/image-digests in the signed bundle). No FILE (a source checkout): nothing to check.
# Kept identical in deploy/install.sh and deploy/falak-ctl.
verify_image_digests() {
  local name digest
  [ -f "$1" ] || return 0
  while read -r name digest; do
    case "$name" in falak-control-plane | falak-edge | falak-builder) ;; *) continue ;; esac
    if ! docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$2/$name:$3" 2>/dev/null | grep -qF "@$digest"; then
      warn "$2/$name:$3 is not the image release $3 recorded ($digest)"
      return 1
    fi
  done < "$1"
}

usage() { sed -n '2,29p' "$0" 2>/dev/null | sed 's/^# \{0,1\}//' || echo "see docs/INSTALL.md"; }

while [ $# -gt 0 ]; do
  case "$1" in
    --domain) DOMAIN="$2"; shift ;;
    --email) EMAIL="$2"; shift ;;
    --admin-email) ADMIN_EMAIL="$2"; shift ;;
    --version) VERSION="$2"; shift ;;
    --observability) OBSERVABILITY=1 ;;
    --no-observability) OBSERVABILITY=0 ;;
    --allow-local-server) ALLOW_LOCAL_SERVER=1 ;;
    --tls) TLS="$2"; shift ;;
    --repo) REPO="$2"; shift ;;
    --image-prefix) IMAGE_PREFIX="$2"; shift ;;
    --build-from-source) FROM_SOURCE=1 ;;
    --ref) REF="$2"; shift ;;
    --source-dir) SOURCE_DIR="$2"; shift ;;
    --http-port) HTTP_PORT="$2"; shift ;;
    --https-port) HTTPS_PORT="$2"; shift ;;
    --dir) FALAK_DIR="$2"; shift ;;
    --skip-dns-check) SKIP_DNS=1 ;;
    --restore-from) RESTORE_FROM="${2:-}"; shift ;;
    --restore-from=*) RESTORE_FROM="${1#--restore-from=}" ;;
    --force) FORCE=1 ;;
    -h|--help) usage; exit 0 ;;
    *) die "unknown option: $1 (see --help)" ;;
  esac
  shift
done

ENV_FILE="$FALAK_DIR/.env"

# --- .env helpers (same format as falak-ctl) -------------------------------------------------------------
env_get() {
  local v
  v="$(grep -E "^$1=" "$ENV_FILE" 2>/dev/null | tail -1 | cut -d= -f2- || true)"
  printf '%s' "${v:-${2:-}}"
}
env_set() {
  local tmp
  tmp="$(mktemp "$FALAK_DIR/.env.XXXXXX")"
  chmod 600 "$tmp"
  if grep -qE "^$1=" "$ENV_FILE" 2>/dev/null; then
    awk -v k="$1" -v v="$2" 'BEGIN { FS = OFS = "=" } $1 == k { print k "=" v; next } { print }' "$ENV_FILE" > "$tmp"
  else
    { cat "$ENV_FILE" 2>/dev/null || true; printf '%s=%s\n' "$1" "$2"; } > "$tmp"
  fi
  mv "$tmp" "$ENV_FILE"
}
env_default() { [ -n "$(env_get "$1")" ] || env_set "$1" "$2"; }   # set only when missing (secrets)
rand_hex() { od -An -tx1 -N"$1" /dev/urandom | tr -d ' \n'; }

# Reuse settings of an existing install when flags are omitted (idempotent re-runs, upgrades of flags).
if [ -f "$ENV_FILE" ]; then
  DOMAIN="${DOMAIN:-$(env_get FALAK_DOMAIN)}"
  EMAIL="${EMAIL:-$(env_get FALAK_ACME_EMAIL)}"
  TLS="${TLS:-$(env_get FALAK_TLS)}"
  REPO="${REPO:-$(env_get FALAK_REPO)}"
  IMAGE_PREFIX="${IMAGE_PREFIX:-$(env_get FALAK_IMAGE_PREFIX)}"
  VERSION="${VERSION:-$(env_get FALAK_VERSION)}"
  HTTP_PORT="${HTTP_PORT:-$(env_get FALAK_HTTP_PORT)}"
  HTTPS_PORT="${HTTPS_PORT:-$(env_get FALAK_HTTPS_PORT)}"
  SOURCE_DIR="${SOURCE_DIR:-$(env_get FALAK_DEPLOY_SOURCE)}"
  if [ -z "$OBSERVABILITY" ]; then
    case "$(env_get COMPOSE_PROFILES)" in *observability*) OBSERVABILITY=1 ;; esac
  fi
fi
TLS="${TLS:-acme}"
REPO="${REPO:-$DEFAULT_REPO}"
HTTP_PORT="${HTTP_PORT:-80}"
HTTPS_PORT="${HTTPS_PORT:-443}"
OBSERVABILITY="${OBSERVABILITY:-0}"
ADMIN_EMAIL="${ADMIN_EMAIL:-$EMAIL}"
OWNER_LC="$(printf '%s' "${REPO%%/*}" | tr '[:upper:]' '[:lower:]')"
IMAGE_PREFIX="${IMAGE_PREFIX:-ghcr.io/$OWNER_LC}"
# Source builds are tagged locally (never pushed) unless a prefix was given explicitly.
if [ "$FROM_SOURCE" = 1 ] && [ -z "${FALAK_IMAGE_PREFIX:-}" ] && [ "$IMAGE_PREFIX" = "ghcr.io/$OWNER_LC" ]; then
  IMAGE_PREFIX="falak-local"
fi

# --- validation -----------------------------------------------------------------------------------------
[ -n "$DOMAIN" ] || die "--domain is required (e.g. --domain falak.example.com)"
printf '%s' "$DOMAIN" | grep -Eq '^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$' \
  || die "--domain '$DOMAIN' is not a valid lower-case DNS name"
case "$TLS" in acme|internal) ;; *) die "--tls must be acme or internal" ;; esac
if [ "$TLS" = acme ]; then
  [ -n "$EMAIL" ] || die "--email is required for Let's Encrypt (--tls acme)"
  { [ "$HTTP_PORT" = 80 ] && [ "$HTTPS_PORT" = 443 ]; } || die "Let's Encrypt needs ports 80/443 (use --tls internal for custom ports)"
fi
[ -n "$ADMIN_EMAIL" ] || ADMIN_EMAIL="admin@$DOMAIN"
printf '%s' "$ADMIN_EMAIL" | grep -Eq '^[^@ ]+@[^@ ]+\.[^@ ]+$' || die "invalid e-mail: $ADMIN_EMAIL"
# restore_from_valid REF : s3://latest or s3://<backup name> (falak-backup-…, no path).
restore_from_valid() {
  case "$1" in
    s3://latest) return 0 ;;
    s3://falak-backup-*) case "${1#s3://}" in */* | *..* | *[!A-Za-z0-9._-]*) return 1 ;; esac; return 0 ;;
    *) return 1 ;;
  esac
}
if [ -n "$RESTORE_FROM" ]; then
  restore_from_valid "$RESTORE_FROM" || die "--restore-from must be s3://latest or s3://falak-backup-<…> (got '$RESTORE_FROM')"
fi
AGENTS_HOST="agents.$DOMAIN"
REGISTRY_HOST="registry.$DOMAIN"
GRAFANA_HOST=""; [ "$OBSERVABILITY" = 1 ] && GRAFANA_HOST="grafana.$DOMAIN"
PORT_SUFFIX=""; [ "$HTTPS_PORT" != 443 ] && PORT_SUFFIX=":$HTTPS_PORT"

# --- preflight ------------------------------------------------------------------------------------------
preflight() {
  info "Preflight checks"
  [ "$(id -u)" = 0 ] || die "run as root (curl ... | sudo bash -s -- ...)"

  local id ver
  id="$(os_field ID)"; ver="$(os_field VERSION_ID)"
  if os_supported "$id" "$ver"; then ok "OS: $id $ver"
  else force_or_die "unsupported OS '$id $ver'" "unsupported OS '$id $ver': Falak supports $SUPPORTED_OS (--force to try anyway)"; fi

  local arch; arch="$(uname -m)"
  case "$arch" in x86_64|aarch64) ok "arch: $arch" ;; *) die "unsupported CPU architecture $arch (amd64/arm64 only)" ;; esac

  local mem_mb
  mem_mb="$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)"
  if [ "$mem_mb" -lt 1900 ]; then
    force_or_die "only ${mem_mb} MB RAM" "Falak needs at least 2 GB RAM (found ${mem_mb} MB; --force to try anyway)"
  elif [ "$mem_mb" -lt 3800 ]; then warn "${mem_mb} MB RAM: works, but 4 GB is recommended (builds are memory-hungry)"
  else ok "RAM: ${mem_mb} MB"; fi
  if [ "$OBSERVABILITY" = 1 ] && [ "$mem_mb" -lt 7600 ]; then
    warn "--observability on ${mem_mb} MB RAM is tight (8 GB recommended); consider a separate observability host"
  fi

  mkdir -p "$FALAK_DIR"
  local free_gb
  free_gb="$(df -Pk "$FALAK_DIR" | awk 'NR==2 {print int($4/1024/1024)}')"
  if [ "$free_gb" -lt 10 ]; then
    force_or_die "only ${free_gb} GB free disk" "at least 10 GB free disk needed on $FALAK_DIR (found ${free_gb} GB; --force to try anyway)"
  elif [ "$free_gb" -lt 25 ]; then warn "${free_gb} GB free disk (25+ GB recommended for artifacts, backups and images)"
  else ok "disk: ${free_gb} GB free"; fi

  local p holder
  for p in "$HTTP_PORT" "$HTTPS_PORT"; do
    holder="$(ss -Hltnp "sport = :$p" 2>/dev/null | sed -n 's/.*users:(("\([^"]*\)".*/\1/p' | head -1 || true)"
    if [ -z "$holder" ]; then ok "port $p free"
    elif docker ps --format '{{.Names}}' 2>/dev/null | grep -q "^${FALAK_PROJECT}-edge-"; then ok "port $p in use by Falak (re-run)"
    else die "port $p is in use by '$holder'. Stop it (e.g. systemctl disable --now nginx apache2 caddy) and re-run."; fi
  done

  check_dns
}

public_ip() { curl "-$1" -fsS --max-time 6 "https://api$([ "$1" = 6 ] && echo 6).ipify.org" 2>/dev/null || true; }

resolve() {
  if command -v dig >/dev/null 2>&1; then
    { dig +short A "$1" @1.1.1.1; dig +short AAAA "$1" @1.1.1.1; } 2>/dev/null | grep -E '^[0-9a-fA-F:.]+$' || true
  else
    getent ahosts "$1" 2>/dev/null | awk '{print $1}' | sort -u || true
  fi
}

check_dns() {
  if [ "$SKIP_DNS" = 1 ] || [ "$TLS" = internal ]; then
    warn "DNS check skipped ($([ "$TLS" = internal ] && echo '--tls internal' || echo '--skip-dns-check'))"
    return 0
  fi
  local ip4 ip6 host addrs failed=0
  ip4="$(public_ip 4)"; ip6="$(public_ip 6)"
  [ -n "$ip4$ip6" ] || die "could not determine this host's public IP (no internet access?). Use --skip-dns-check if you are sure DNS is right."
  ok "public IP: ${ip4:-} ${ip6:-}"
  for host in "$DOMAIN" "$AGENTS_HOST" "$REGISTRY_HOST" $GRAFANA_HOST; do
    addrs="$(resolve "$host")"
    if { [ -n "$ip4" ] && grep -qx "$ip4" <<<"$addrs"; } || { [ -n "$ip6" ] && grep -qx "$ip6" <<<"$addrs"; }; then
      ok "DNS $host -> $(tr '\n' ' ' <<<"$addrs")"
    else
      failed=1
      if [ -z "$addrs" ]; then warn "DNS: $host does not resolve"; else warn "DNS: $host -> $(tr '\n' ' ' <<<"$addrs") (this host is ${ip4:-$ip6})"; fi
    fi
  done
  if [ "$failed" = 1 ]; then
    printf '\n  Create these DNS records at your DNS provider, wait until they resolve, then re-run:\n\n' >&2
    for host in "$DOMAIN" "$AGENTS_HOST" "$REGISTRY_HOST" $GRAFANA_HOST; do
      [ -n "$ip4" ] && printf '    %-40s A     %s\n' "$host" "$ip4" >&2
      [ -n "$ip6" ] && printf '    %-40s AAAA  %s\n' "$host" "$ip6" >&2
    done
    printf '\n  (Cloudflare: DNS only / grey cloud — the agent API uses its own certificate and mTLS.)\n' >&2
    die "DNS does not point at this host yet (or re-run with --skip-dns-check)"
  fi
}

# --- Docker ---------------------------------------------------------------------------------------------
# docker_codename ID CODENAME : the suite of Docker's apt repository to use. Docker publishes each release's own
# codename (jammy, noble, resolute; bookworm, trixie), usually some weeks after a new release ships; until then
# fall back to the previous LTS / stable suite, whose packages install and run on the newer release. Codenames
# Docker is known to publish (jammy, noble, bookworm) always use their own suite, never probed: a probe that fails
# for a network hiccup must not install another release's packages. Others are probed (with retries).
docker_codename() {
  local id="$1" codename="$2" fallback
  case "$id:$codename" in ubuntu:jammy|ubuntu:noble|debian:bookworm) echo "$codename"; return 0 ;; esac
  case "$id" in debian) fallback=bookworm ;; *) fallback=noble ;; esac
  if [ -z "$codename" ]; then echo "$fallback"; return 0; fi
  if curl -fsSI --max-time 15 --retry 3 --retry-delay 2 "https://download.docker.com/linux/$id/dists/$codename/Release" >/dev/null 2>&1; then
    echo "$codename"
  else
    warn "Docker's apt repository has no '$codename' suite (yet); using '$fallback' packages"
    echo "$fallback"
  fi
}

install_docker() {
  info "Docker"
  if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    ok "$(docker --version)"
  else
    local id codename
    id="$(os_field ID)"; codename="$(os_field VERSION_CODENAME)"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq ca-certificates curl gnupg >/dev/null
    install -m 0755 -d /etc/apt/keyrings
    codename="$(docker_codename "$id" "$codename")"
    curl -fsSL "https://download.docker.com/linux/$id/gpg" -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/$id $codename stable" \
      > /etc/apt/sources.list.d/docker.list
    apt-get update -qq
    apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin >/dev/null
    systemctl enable --now docker >/dev/null 2>&1 || true
    ok "installed $(docker --version)"
  fi
  docker info >/dev/null 2>&1 || die "the Docker daemon is not running (systemctl status docker)"
  local cv major minor
  cv="$(docker compose version --short 2>/dev/null | sed 's/^v//')"
  major="${cv%%.*}"; minor="$(echo "$cv" | cut -d. -f2)"
  if [ "${major:-0}" -lt 2 ] || { [ "$major" = 2 ] && [ "${minor:-0}" -lt 24 ]; }; then
    die "Docker Compose $cv is too old (need 2.24+): apt-get install --only-upgrade docker-compose-plugin"
  fi
  ok "Docker Compose $cv"
  command -v openssl >/dev/null 2>&1 || apt-get install -y -qq openssl >/dev/null 2>&1 || true
}

# --- release / deploy files -----------------------------------------------------------------------------
resolve_version() {
  if [ -n "$VERSION" ]; then return 0; fi
  if [ "$FROM_SOURCE" = 1 ]; then VERSION="${REF:-main}"; return 0; fi
  VERSION="$(curl -fsSL "https://api.github.com/repos/$REPO/releases/latest" 2>/dev/null \
    | sed -n 's/.*"tag_name": *"\([^"]*\)".*/\1/p' | head -1 || true)"
  [ -n "$VERSION" ] || die "could not find the latest release of github.com/$REPO (pass --version vX.Y.Z)"
}

fetch_files() {
  info "Falak $VERSION files"
  local stage
  stage="$(mktemp -d)"
  # Our own clone (saved as FALAK_DEPLOY_SOURCE by an earlier run) is fetched again, or a re-run builds stale code.
  if [ "$FROM_SOURCE" = 1 ] && { [ -z "$SOURCE_DIR" ] || [ "$SOURCE_DIR" = "$FALAK_DIR/src" ]; }; then
    command -v git >/dev/null 2>&1 || apt-get install -y -qq git >/dev/null
    rm -rf "$FALAK_DIR/src"
    git clone -q --depth 1 --branch "${REF:-$VERSION}" "https://github.com/$REPO.git" "$FALAK_DIR/src" \
      || die "git clone of github.com/$REPO (${REF:-$VERSION}) failed"
    SOURCE_DIR="$FALAK_DIR/src"
  fi
  if [ -n "$SOURCE_DIR" ]; then
    [ -f "$SOURCE_DIR/deploy/compose.yml" ] || die "$SOURCE_DIR has no deploy/compose.yml"
    cp -R "$SOURCE_DIR/deploy" "$stage/deploy"
    cp -R "$SOURCE_DIR/observability" "$stage/observability"
    ok "from $SOURCE_DIR"
  else
    local base="https://github.com/$REPO/releases/download/$VERSION"
    curl -fsSL --retry 3 -o "$stage/falak-deploy.tar.gz" "$base/falak-deploy.tar.gz" \
      || die "download failed: $base/falak-deploy.tar.gz (does release $VERSION exist?)"
    curl -fsSL --retry 3 -o "$stage/SHA256SUMS" "$base/SHA256SUMS" || die "download failed: $base/SHA256SUMS"
    verify_sums_sig "$stage" "$base"
    (cd "$stage" && grep ' falak-deploy.tar.gz$' SHA256SUMS | sha256sum -c --quiet -) || die "checksum mismatch for falak-deploy.tar.gz"
    tar -xzf "$stage/falak-deploy.tar.gz" -C "$stage"
    ok "release bundle verified (SHA256SUMS${RELEASE_PUBKEY:+, signed})"
  fi
  local d
  for d in deploy observability; do
    rm -rf "${FALAK_DIR:?}/$d.prev"
    [ -d "$FALAK_DIR/$d" ] && mv "$FALAK_DIR/$d" "$FALAK_DIR/$d.prev"
    mv "$stage/$d" "$FALAK_DIR/$d"
  done
  rm -rf "$stage"
  mkdir -p "$FALAK_DIR/edge"
  install -m 0755 "$FALAK_DIR/deploy/falak-ctl" /usr/local/bin/falak-ctl
  ok "falak-ctl installed to /usr/local/bin/falak-ctl"
}

build_images() {
  info "Building images from source ($SOURCE_DIR, this takes a while)"
  local src="$SOURCE_DIR"
  docker build -q -f "$src/control-plane/Dockerfile" --build-arg FALAK_VERSION="$VERSION" -t "$IMAGE_PREFIX/falak-control-plane:$VERSION" "$src" >/dev/null
  ok "falak-control-plane"
  docker build -q -f "$src/deploy/builder.Dockerfile" --build-arg FALAK_VERSION="$VERSION" -t "$IMAGE_PREFIX/falak-builder:$VERSION" "$src" >/dev/null
  ok "falak-builder"
  docker build -q -f "$src/deploy/edge/Dockerfile" --build-arg FALAK_VERSION="$VERSION" -t "$IMAGE_PREFIX/falak-edge:$VERSION" "$src/deploy/edge" >/dev/null
  ok "falak-edge"
}

# --- .env -----------------------------------------------------------------------------------------------
write_env() {
  info "Configuration ($ENV_FILE)"
  umask 077
  local fresh=0
  [ -f "$ENV_FILE" ] || { fresh=1; printf '# Falak settings and secrets — generated by install.sh. Keep private; back it up (falak-ctl backup).\n' > "$ENV_FILE"; }
  chmod 600 "$ENV_FILE"

  # secrets: generated once, never rotated by re-runs
  env_default APP_KEY "base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
  env_default DB_PASSWORD "$(rand_hex 24)"
  env_default REDIS_PASSWORD "$(rand_hex 24)"
  env_default REVERB_APP_ID "falak"
  env_default REVERB_APP_KEY "$(rand_hex 10)"
  env_default REVERB_APP_SECRET "$(rand_hex 20)"
  env_default FALAK_BUILDER_TOKEN "kbt_$(rand_hex 24)"
  env_default FALAK_OTLP_TOKEN "$(rand_hex 24)"
  env_default GRAFANA_ADMIN_PASSWORD "$(rand_hex 16)"

  # settings: follow the flags
  env_set FALAK_REPO "$REPO"
  env_set FALAK_IMAGE_PREFIX "$IMAGE_PREFIX"
  env_set FALAK_VERSION "$VERSION"
  env_set FALAK_DOMAIN "$DOMAIN"
  env_set FALAK_URL "https://$DOMAIN$PORT_SUFFIX"
  env_set FALAK_AGENT_API_HOST "$AGENTS_HOST"
  env_set FALAK_AGENT_API_URL "https://$AGENTS_HOST$PORT_SUFFIX/agent/v1"
  # Built-in image registry (docker builds push, servers pull; basic auth at the edge).
  env_set FALAK_REGISTRY_HOST "$REGISTRY_HOST"
  env_set FALAK_REGISTRY_URL "$REGISTRY_HOST$PORT_SUFFIX"
  env_default FALAK_REGISTRY_USERNAME falak
  env_default FALAK_REGISTRY_PASSWORD "$(rand_hex 24)"
  env_set FALAK_ACME_EMAIL "$EMAIL"
  env_set FALAK_TLS "$TLS"
  env_set FALAK_HTTP_PORT "$HTTP_PORT"
  env_set FALAK_HTTPS_PORT "$HTTPS_PORT"
  if [ "$TLS" = internal ]; then env_set FALAK_HSTS "max-age=0"; else env_set FALAK_HSTS "max-age=31536000"; fi
  env_default FALAK_EDGE_SUBNET "10.213.77.0/24"
  # Servers -> Add server -> This machine: off unless asked for (falak-ctl local-server allow|deny changes it later).
  if [ "$ALLOW_LOCAL_SERVER" = 1 ]; then env_set FALAK_HOST_ALLOW_LOCAL_SERVER 1; else env_default FALAK_HOST_ALLOW_LOCAL_SERVER 0; fi
  env_default FALAK_BACKUP_KEEP "14"
  if [ -n "$SOURCE_DIR" ]; then env_set FALAK_DEPLOY_SOURCE "$SOURCE_DIR"; fi
  if [ "$FROM_SOURCE" = 1 ]; then env_set FALAK_PULL 0; else env_default FALAK_PULL 1; fi

  if [ "$OBSERVABILITY" = 1 ]; then
    env_set COMPOSE_PROFILES observability
    env_set FALAK_GRAFANA_HOST "$GRAFANA_HOST"
    env_set FALAK_GRAFANA_PUBLIC_URL "https://$GRAFANA_HOST$PORT_SUFFIX"
    env_set FALAK_GRAFANA_URL "http://grafana:3000"
    env_set FALAK_LOKI_URL "http://loki:3100"
    env_set FALAK_TEMPO_URL "http://tempo:3200"
    env_set FALAK_METRICS_QUERY_URL "http://gateway:9090/prometheus"
    env_set FALAK_OTLP_ENDPOINT "https://$DOMAIN$PORT_SUFFIX/otlp"
  else
    local k
    for k in COMPOSE_PROFILES FALAK_GRAFANA_HOST FALAK_GRAFANA_PUBLIC_URL FALAK_GRAFANA_URL FALAK_LOKI_URL FALAK_TEMPO_URL FALAK_METRICS_QUERY_URL FALAK_OTLP_ENDPOINT; do
      env_set "$k" ""
    done
  fi
  if [ "$fresh" = 1 ]; then ok "generated new secrets"; else ok "kept existing secrets, updated settings"; fi
}

# The key-encryption key (/opt/falak/secrets/kek, 32 bytes from /dev/urandom, 0400, owned by the containers'
# www-data): every secret in the database is sealed under it. Created once; a re-run keeps it.
write_kek() {
  info "Key-encryption key ($FALAK_DIR/secrets/kek)"
  kctl kek init || die "could not create the key-encryption key"
}

check_subnet() {
  local subnet prefix
  subnet="$(env_get FALAK_EDGE_SUBNET)"; prefix="${subnet%.*}."
  if ip route 2>/dev/null | grep -v 'br-' | grep -q "^${prefix}"; then
    die "FALAK_EDGE_SUBNET $subnet overlaps a network on this host. Set another /24 in $ENV_FILE (FALAK_EDGE_SUBNET=...) and re-run."
  fi
}

# --- start ----------------------------------------------------------------------------------------------
kctl() { FALAK_DIR="$FALAK_DIR" FALAK_PROJECT="$FALAK_PROJECT" /usr/local/bin/falak-ctl "$@"; }
dc() { COMPOSE_PROFILES="$(env_get COMPOSE_PROFILES)" docker compose -p "$FALAK_PROJECT" --env-file "$ENV_FILE" -f "$FALAK_DIR/deploy/compose.yml" "$@"; }

start_stack() {
  info "Starting Falak $VERSION"
  if [ "$(env_get FALAK_PULL 1)" != 0 ]; then
    pull_retry dc pull --quiet || die "pulling images from $IMAGE_PREFIX failed (is release $VERSION published? private packages need 'docker login ghcr.io')"
    verify_image_digests "$FALAK_DIR/deploy/image-digests" "$IMAGE_PREFIX" "$VERSION" || die "refusing images that differ from release $VERSION"
    ok "images pulled"
  fi
  if ! dc up -d --wait --wait-timeout 600 --remove-orphans; then
    dc ps -a >&2 || true
    dc logs --tail=60 control-plane agent-api edge >&2 || true
    die "the stack did not become healthy — see the logs above, then: falak-ctl doctor"
  fi
  # A re-run replaces deploy/ + observability/: recreate services whose mounted config files changed.
  kctl reload-configs || die "recreating services with changed config files failed — see: falak-ctl logs"
  ok "all services healthy"
}

setup_grafana_token() {
  [ "$OBSERVABILITY" = 1 ] || return 0
  [ -z "$(env_get FALAK_GRAFANA_TOKEN)" ] || return 0
  info "Grafana service account for Falak"
  local pass sa token
  pass="$(env_get GRAFANA_ADMIN_PASSWORD)"
  sa="$(dc exec -T grafana sh -c "wget -q -O - --header 'Content-Type: application/json' --post-data '{\"name\":\"falak\",\"role\":\"Admin\"}' http://admin:$pass@127.0.0.1:3000/api/serviceaccounts" 2>/dev/null \
    | sed -n 's/.*"id":\([0-9]*\).*/\1/p' | head -1 || true)"
  if [ -z "$sa" ]; then
    sa="$(dc exec -T grafana sh -c "wget -q -O - 'http://admin:$pass@127.0.0.1:3000/api/serviceaccounts/search?query=falak'" 2>/dev/null \
      | sed -n 's/.*"serviceAccounts":\[{"id":\([0-9]*\).*/\1/p' | head -1 || true)"
  fi
  [ -n "$sa" ] || { warn "could not create the Grafana service account (set FALAK_GRAFANA_TOKEN in $ENV_FILE manually)"; return 0; }
  token="$(dc exec -T grafana sh -c "wget -q -O - --header 'Content-Type: application/json' --post-data '{\"name\":\"falak-$(date +%s)\"}' http://admin:$pass@127.0.0.1:3000/api/serviceaccounts/$sa/tokens" 2>/dev/null \
    | sed -n 's/.*"key":"\([^"]*\)".*/\1/p' | head -1 || true)"
  [ -n "$token" ] || { warn "could not create a Grafana token (set FALAK_GRAFANA_TOKEN manually)"; return 0; }
  env_set FALAK_GRAFANA_TOKEN "$token"
  dc up -d --wait --wait-timeout 300 >/dev/null
  ok "FALAK_GRAFANA_TOKEN configured"
}

create_admin() {
  info "First administrator"
  local out password
  out="$(dc exec -T control-plane php artisan falak:admin "$ADMIN_EMAIL" --json 2>/dev/null | tail -1 || true)"
  printf '%s' "$out" | grep -q '"user_id"' || die "falak:admin failed: $out (retry: falak-ctl admin create $ADMIN_EMAIL)"
  password="$(printf '%s' "$out" | sed -n 's/.*"password":"\([^"]*\)".*/\1/p')"
  if [ -n "$password" ]; then
    ADMIN_PASSWORD="$password"
    ok "created $ADMIN_EMAIL"
  else
    ADMIN_PASSWORD=""
    ok "$ADMIN_EMAIL already exists (password unchanged; reset: falak-ctl admin reset-password $ADMIN_EMAIL)"
  fi
  record_operator_organization "$out"
}

# The first admin's organization operates the install: it alone sees the control plane's disaster recovery (the
# banner, Settings → Disaster recovery, dr.* alerts). Recorded once; a re-run never moves it.
record_operator_organization() { # record_operator_organization FALAK_ADMIN_JSON
  local id
  [ -z "$(env_get FALAK_DR_ORGANIZATION)" ] || return 0
  id="$(printf '%s' "$1" | sed -n 's/.*"organization_id":"\([0-9a-zA-Z]\{26\}\)".*/\1/p')"
  [ -n "$id" ] || { warn "could not read the first admin's organization: set FALAK_DR_ORGANIZATION in $ENV_FILE"; return 0; }
  env_set FALAK_DR_ORGANIZATION "$id"
  dc up -d --wait --wait-timeout 300 >/dev/null 2>&1 || warn "restarting with FALAK_DR_ORGANIZATION failed: falak-ctl up"
}

# --- restore a lost control plane (--restore-from) --------------------------------------------------------
# The DR settings falak-ctl needs, from the environment or asked on the terminal (stdin is the script under curl |
# bash, so /dev/tty). Secrets are read without echo, kept in unexported shell variables and handed to falak-ctl
# only in its environment (prefix assignments, never a command line) or 0600 files removed on exit.
restore_prompt() { # restore_prompt KEY LABEL SECRET DEFAULT
  local value="${!1:-}"
  if [ -z "$value" ] && [ -r /dev/tty ] && [ -w /dev/tty ]; then
    printf '  %s%s: ' "$2" "${4:+ [$4]}" > /dev/tty
    if [ "$3" = 1 ]; then IFS= read -rs value < /dev/tty || true; printf '\n' > /dev/tty
    else IFS= read -r value < /dev/tty || true; fi
  fi
  value="${value:-$4}"
  [ -n "$value" ] || die "--restore-from needs $1 (set it in the environment)"
  printf -v "R_$1" '%s' "$value"
}

collect_restore_settings() {
  restore_prompt FALAK_BACKUP_S3_ENDPOINT "S3 endpoint of the DR bucket (https://…)" 0 ""
  restore_prompt FALAK_BACKUP_S3_BUCKET "Bucket" 0 ""
  restore_prompt FALAK_BACKUP_S3_REGION "Region" 0 "us-east-1"
  restore_prompt FALAK_BACKUP_S3_PREFIX "Key prefix" 0 "falak"
  restore_prompt FALAK_BACKUP_S3_ACCESS_KEY "Access key id" 0 ""
  restore_prompt FALAK_BACKUP_S3_SECRET_KEY "Secret access key" 1 ""
  restore_prompt FALAK_BACKUP_PASSPHRASE "DR passphrase" 1 ""
}

restore_control_plane() {
  info "Restoring the control plane from $RESTORE_FROM"
  collect_restore_settings
  if [ "$(env_get FALAK_PULL 1)" != 0 ]; then
    pull_retry dc pull --quiet || die "pulling images from $IMAGE_PREFIX failed"
    verify_image_digests "$FALAK_DIR/deploy/image-digests" "$IMAGE_PREFIX" "$VERSION" || die "refusing images that differ from release $VERSION"
    ok "images pulled"
  fi
  # The backup's .env, KEK, Fleet CA and edge PKI replace this fresh install's; this host's version and image source
  # are kept (falak-ctl restore pins them).
  FALAK_BACKUP_S3_ENDPOINT="$R_FALAK_BACKUP_S3_ENDPOINT" FALAK_BACKUP_S3_BUCKET="$R_FALAK_BACKUP_S3_BUCKET" \
    FALAK_BACKUP_S3_REGION="$R_FALAK_BACKUP_S3_REGION" FALAK_BACKUP_S3_PREFIX="$R_FALAK_BACKUP_S3_PREFIX" \
    FALAK_BACKUP_S3_ACCESS_KEY="$R_FALAK_BACKUP_S3_ACCESS_KEY" FALAK_BACKUP_S3_SECRET_KEY="$R_FALAK_BACKUP_S3_SECRET_KEY" \
    FALAK_BACKUP_PASSPHRASE="$R_FALAK_BACKUP_PASSPHRASE" \
    kctl restore "$RESTORE_FROM" --yes || die "the restore failed (see above). Fix it and re-run with the same --restore-from"
  # Only now the schedule: a timer that ran before a failed restore would upload an empty install as the latest.
  local secret pass
  RESTORE_TMP="$(mktemp -d)"; chmod 700 "$RESTORE_TMP"
  trap 'rm -rf "$RESTORE_TMP"' EXIT
  secret="$RESTORE_TMP/secret"; pass="$RESTORE_TMP/pass"
  ( umask 077; printf '%s\n' "$R_FALAK_BACKUP_S3_SECRET_KEY" > "$secret"; printf '%s\n' "$R_FALAK_BACKUP_PASSPHRASE" > "$pass" )
  if ! kctl dr setup --yes --endpoint "$R_FALAK_BACKUP_S3_ENDPOINT" --bucket "$R_FALAK_BACKUP_S3_BUCKET" \
      --region "$R_FALAK_BACKUP_S3_REGION" --prefix "$R_FALAK_BACKUP_S3_PREFIX" --access-key "$R_FALAK_BACKUP_S3_ACCESS_KEY" \
      --secret-key-file "$secret" --passphrase-file "$pass"; then
    warn "restored, but scheduling backups failed: run falak-ctl dr setup"
  fi
  rm -rf "$RESTORE_TMP"
  local restored
  restored="$(env_get FALAK_DOMAIN)"
  if [ "$restored" != "$DOMAIN" ]; then
    warn "the backup is of $restored (not --domain $DOMAIN): the panel answers on $restored; point its DNS here"
  fi
  DOMAIN="$restored"; AGENTS_HOST="$(env_get FALAK_AGENT_API_HOST)"; REGISTRY_HOST="$(env_get FALAK_REGISTRY_HOST)"
  ok "control plane restored: agents reconnect with their certificates once $AGENTS_HOST points here"
}

summary() {
  printf '\n%sFalak %s is running.%s\n\n' "$B" "$VERSION" "$N"
  printf '  Panel        https://%s%s\n' "$DOMAIN" "$PORT_SUFFIX"
  printf '  Agent API    https://%s%s/agent/v1  (mTLS, Fleet CA)\n' "$AGENTS_HOST" "$PORT_SUFFIX"
  printf '  Registry     https://%s%s  (built-in image registry; user falak, FALAK_REGISTRY_PASSWORD in %s)\n' "$REGISTRY_HOST" "$PORT_SUFFIX" "$ENV_FILE"
  [ -n "$GRAFANA_HOST" ] && printf '  Grafana      https://%s%s  (admin / GRAFANA_ADMIN_PASSWORD in %s)\n' "$GRAFANA_HOST" "$PORT_SUFFIX" "$ENV_FILE"
  if [ -n "${ADMIN_PASSWORD:-}" ]; then
    printf '\n  %sAdmin login  %s\n  Password     %s%s\n' "$B" "$ADMIN_EMAIL" "$ADMIN_PASSWORD" "$N"
    printf '  (shown once — store it in a password manager)\n'
  fi
  printf '\n  Next: falak-ctl status | falak-ctl doctor | falak-ctl backup\n'
  printf '  %sSave the emergency kit now:%s falak-ctl kek export /root/falak-emergency-kit.txt — the key that\n' "$Y" "$N"
  printf '  decrypts every secret Falak stores. Keep it offline, apart from the backups, then delete it from this host.\n'
  printf '  %sBack up regularly and copy backups off this host:%s they contain the Fleet CA — losing it\n' "$Y" "$N"
  printf '  means re-enrolling every server. Docs: https://github.com/%s/blob/main/docs/INSTALL.md\n\n' "$REPO"
}

main() {
  preflight
  install_docker
  resolve_version
  fetch_files
  write_env
  write_kek
  check_subnet
  # state/host + falak-host.path (the panel's update button), falakcp.slice (the panel's reserved share).
  kctl ensure-host || die "falak-ctl ensure-host failed (see above)"
  if [ "$FROM_SOURCE" = 1 ]; then build_images; fi
  if [ -n "$RESTORE_FROM" ]; then
    restore_control_plane
    ADMIN_PASSWORD=""
    summary
    return 0
  fi
  start_stack
  setup_grafana_token
  create_admin
  summary
}

# stdin is the script itself under `curl | bash`: never let a command read it. deploy/tests/*.sh source the
# script (FALAK_INSTALL_SOURCED=1) for function-level tests.
if [ "${FALAK_INSTALL_SOURCED:-0}" != 1 ]; then main </dev/null; fi
