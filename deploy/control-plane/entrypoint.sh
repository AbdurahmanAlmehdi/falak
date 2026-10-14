#!/bin/sh
# Falak control-plane entrypoint.
#   roles: web (default) | agent-api | horizon | reverb | scheduler | relay | <any command, e.g. php artisan ...>
#
#   web        the panel (FrankenPHP). Worker mode by default: Laravel boots once per worker thread.
#   agent-api  FrankenPHP for machine traffic: agent API (/agent/*), installer (/install/*) and the builder's
#              internal API (/api/internal/*). Agents and builders hold long-polls (one PHP thread each for
#              up to 30 s), so they get their own thread pool and can never starve the panel.
#   relay      the terminal relay (falak-relay, Go): pipes console bytes between agents and browsers/the CLI.
#              Needs only APP_KEY (its tokens are signed with a key derived from it), no database.
#
#   FALAK_MIGRATE=1          web role runs `migrate --force` before serving (set on exactly one service)
#   FALAK_WAIT_TIMEOUT=120   seconds to wait for Postgres/Valkey before giving up
#   FALAK_MIGRATE_WAIT_TIMEOUT=900  seconds the other roles wait for the web role's migrations before giving up
#   FALAK_KEK_GENERATE=1     web role creates a missing local KEK at FALAK_KEK_PATH (the sim only; production
#                            installs get it from install.sh / falak-ctl, outside the containers)
#   FALAK_EDGE_AGENT_API_HOST  issue/renew the agent-API server certificate from the Fleet CA into
#                           $FALAK_CA_PATH (agent-api.pem/.key) — the edge serves the agents host with it.
#
# PHP thread pool (docs/INSTALL.md, "Performance"; defaults scale with the CPUs visible to the container):
#   FALAK_WORKER_MODE=1          web: FrankenPHP worker mode (Laravel Octane); 0 = classic, boot per request
#   FALAK_PHP_WORKERS            web, worker mode: worker threads kept booted            (default 2 x CPUs)
#   FALAK_PHP_THREADS            web, classic mode: threads started                      (default 2 x CPUs, >= 4)
#   FALAK_PHP_MAX_THREADS        web: ceiling the pool autoscales to under load          (default 4 x CPUs, >= 8)
#   FALAK_AGENT_API_THREADS      agent-api: ceiling; size it >= servers + builders + 8   (default 128; 32 started)
# A `num_threads`/`max_threads` in FRANKENPHP_CONFIG (the 0.2.x hotfix in custom.env) takes precedence.
set -eu
cd /app

role="${1:-web}"
ca_dir="${FALAK_CA_PATH:-/falak/ca}"
# CLI OPcache file cache (php-cli.ini): start every container from an empty cache.
rm -rf /tmp/falak-opcache/* 2>/dev/null || true
mkdir -p /tmp/falak-opcache 2>/dev/null || true
mkdir -p "$ca_dir" storage/app/public storage/framework/cache storage/framework/sessions \
  storage/framework/views storage/logs storage/falak 2>/dev/null || true

log() { echo "falak: $*" >&2; }

wait_for_services() {
  timeout="${FALAK_WAIT_TIMEOUT:-120}"
  # shellcheck disable=SC2016  # PHP code
  php -r '
    $deadline = time() + (int) $argv[1];
    $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST") ?: "postgres", getenv("DB_PORT") ?: "5432", getenv("DB_DATABASE") ?: "falak");
    $pg = $redis = false;
    while (time() < $deadline) {
        if (! $pg) {
            try { new PDO($dsn, getenv("DB_USERNAME") ?: "falak", getenv("DB_PASSWORD") ?: "", [PDO::ATTR_TIMEOUT => 3]); $pg = true; }
            catch (Throwable $e) { $err = $e->getMessage(); }
        }
        if (! $redis) {
            $redis = (bool) @fsockopen(getenv("REDIS_HOST") ?: "valkey", (int) (getenv("REDIS_PORT") ?: 6379), $n, $s, 3);
        }
        if ($pg && $redis) { exit(0); }
        sleep(2);
    }
    fwrite(STDERR, "falak: timed out waiting for ".(! $pg ? "Postgres (".($err ?? "?").")" : "Valkey")."\n");
    exit(1);
  ' "$timeout"
}

cache_config() {
  php artisan config:cache --no-interaction >/dev/null
  php artisan route:cache --no-interaction >/dev/null 2>&1 || log "route:cache failed (closure routes?), continuing uncached"
  php artisan view:cache --no-interaction >/dev/null 2>&1 || true
  php artisan event:cache --no-interaction >/dev/null 2>&1 || true
}

# Every PHP role refuses to start without a usable key-encryption key: nothing sealed could be read or written.
# The web role (after migrations) also checks that every data key unwraps with it, i.e. that this is the KEK the
# database was sealed under; the other roles only check the KEK itself (one KMS/Vault call, not one per key).
check_keys() {
  if [ "${FALAK_KEK_GENERATE:-0}" = "1" ] && [ "${FALAK_KEK_PROVIDER:-local}" = "local" ] && [ ! -e "${FALAK_KEK_PATH:-/opt/falak/secrets/kek}" ]; then
    php artisan falak:keys:generate-kek --no-interaction >&2
  fi
  if ! php artisan falak:keys:check "$@" --no-interaction >&2; then
    log "the key-encryption key is unusable (see above; docs/INSTALL.md, \"Encryption keys\"). On the host: falak-ctl doctor"
    exit 1
  fi
}

# Roles other than web wait until no migration is pending: the web role runs them, and until then the database
# may hold values the code can't read yet (v0.10.0: secrets re-sealed from APP_KEY to the KEK). `migrate:status
# --pending=N` exits N while any is pending (and 1 before the migrations table exists).
wait_for_migrations() {
  deadline=$(( $(date +%s) + ${FALAK_MIGRATE_WAIT_TIMEOUT:-900} ))
  waited=0
  until php artisan migrate:status --pending=3 --no-interaction >/dev/null 2>&1; do
    if [ "$(date +%s)" -ge "$deadline" ]; then
      log "migrations are still pending after ${FALAK_MIGRATE_WAIT_TIMEOUT:-900}s (the control-plane service runs them: falak-ctl logs control-plane)"
      exit 1
    fi
    [ "$waited" = 1 ] || log "waiting for the control-plane service to finish the database migrations"
    waited=1
    sleep 3
  done
}

# Agents pin the Falak CA on the mTLS API, so the agents host is served with a certificate issued by the
# Fleet CA (created on first use, key sealed in the database). Renewed 30 days before
# expiry or when it no longer chains to the current CA; the edge hot-reloads when the files change.
ensure_agent_api_cert() {
  host="${FALAK_EDGE_AGENT_API_HOST:-}"
  [ -n "$host" ] || return 0
  # Former agent API names (after `falak-ctl domain set`) stay on the certificate: enrolled agents keep them.
  hosts="$host $(printf '%s' "${FALAK_AGENT_API_HOST_ALIASES:-}" | tr ',' ' ')"
  if [ -s "$ca_dir/agent-api.pem" ] && [ -s "$ca_dir/ca.pem" ] \
     && openssl x509 -in "$ca_dir/agent-api.pem" -noout -checkend 2592000 >/dev/null 2>&1 \
     && openssl verify -CAfile "$ca_dir/ca.pem" "$ca_dir/agent-api.pem" >/dev/null 2>&1; then
    sans="$(openssl x509 -in "$ca_dir/agent-api.pem" -noout -ext subjectAltName 2>/dev/null | tr ',' '\n' | sed -n 's/^ *DNS://p')"
    missing=0
    for h in $hosts; do
      printf '%s\n' "$sans" | grep -qx "$h" || missing=1
    done
    [ "$missing" = 0 ] && return 0
  fi
  log "issuing agent API server certificate for $hosts"
  # shellcheck disable=SC2086
  php artisan fleet:ca:server-cert $hosts --out "$ca_dir" --no-interaction >/dev/null \
    || log "agent API certificate issuance failed (agents cannot connect until this succeeds)"
}

# Non-negative integer or the default.
int_or() {
  case "${1:-}" in
    '' | *[!0-9]*) echo "$2" ;;
    *) echo "$1" ;;
  esac
}

max() { if [ "$1" -gt "$2" ]; then echo "$1"; else echo "$2"; fi; }
min() { if [ "$1" -lt "$2" ]; then echo "$1"; else echo "$2"; fi; }

# Size the FrankenPHP thread pool for $1 (web | agent-api) and pick the PHP mode. Exports the variables the
# Caddyfile reads: FALAK_FRANKENPHP_THREADS, FALAK_PHP_SERVER, FALAK_PHP_WORKERS.
configure_php() {
  cpus="$(nproc 2>/dev/null || echo 2)"
  cpus="$(int_or "$cpus" 2)"
  [ "$cpus" -ge 1 ] || cpus=2
  # 0.2.x hotfix: FRANKENPHP_CONFIG=num_threads N in custom.env. Declaring the counts twice would fail
  # (max_threads < num_threads), so the legacy value wins; the FALAK_* defaults apply once it is removed.
  legacy="$(printf '%s\n' "${FRANKENPHP_CONFIG:-}" | sed -n 's/.*num_threads[[:space:]]\{1,\}\([0-9]\{1,\}\).*/\1/p' | head -n 1)"
  legacy_max=0
  if printf '%s' "${FRANKENPHP_CONFIG:-}" | grep -q 'max_threads'; then legacy_max=1; fi

  workers=0
  mode=classic
  case "$1" in
    web)
      if [ "${FALAK_WORKER_MODE:-1}" != "0" ]; then
        mode=worker
        workers="$(max 1 "$(int_or "${FALAK_PHP_WORKERS:-}" $((cpus * 2)))")"
        # Worker threads plus two regular threads (direct /index.php hits, e.g. from old links).
        threads=$((workers + 2))
      else
        threads="$(max 1 "$(int_or "${FALAK_PHP_THREADS:-}" "$(max 4 $((cpus * 2)))")")"
      fi
      ceiling="$(int_or "${FALAK_PHP_MAX_THREADS:-}" "$(max 8 $((cpus * 4)))")"
      ;;
    agent-api)
      ceiling="$(max 2 "$(int_or "${FALAK_AGENT_API_THREADS:-}" 128)")"
      threads="$(min 32 "$ceiling")"
      ;;
  esac
  ceiling="$(max "$ceiling" "$threads")"

  if [ -n "$legacy" ] || [ "$legacy_max" = 1 ]; then
    log "FRANKENPHP_CONFIG sets the PHP thread count (${FRANKENPHP_CONFIG}); it overrides the $1 defaults. Remove it from custom.env to use the FALAK_* sizing (docs/INSTALL.md)."
    FALAK_FRANKENPHP_THREADS=""
    if [ "$mode" = worker ] && [ -n "$legacy" ] && [ "$workers" -ge "$legacy" ]; then
      workers="$(max 1 $((legacy - 1)))"
    fi
  else
    FALAK_FRANKENPHP_THREADS="num_threads $threads
max_threads $ceiling"
  fi
  FALAK_PHP_SERVER="$mode"
  FALAK_PHP_WORKERS="$(max 1 "$workers")"
  export FALAK_FRANKENPHP_THREADS FALAK_PHP_SERVER FALAK_PHP_WORKERS
  summary="PHP $mode mode"
  if [ "$mode" = worker ]; then summary="$summary, $FALAK_PHP_WORKERS workers"; fi
  if [ -n "$FALAK_FRANKENPHP_THREADS" ]; then
    summary="$summary, $(printf '%s' "$FALAK_FRANKENPHP_THREADS" | tr '\n' ' ')"
  fi
  log "$1: $summary"
}

echo "$role" > /tmp/falak-role 2>/dev/null || true

case "$role" in
  web)
    wait_for_services
    cache_config
    check_keys --kek-only
    if [ "${FALAK_MIGRATE:-0}" = "1" ]; then
      log "running migrations"
      php artisan migrate --force --no-interaction
    fi
    check_keys
    ensure_agent_api_cert
    # Renew the agent API certificate daily while running (cheap no-op when still valid).
    ( while sleep 86400; do ensure_agent_api_cert; done ) &
    configure_php web
    exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
    ;;
  agent-api)
    wait_for_services
    cache_config
    check_keys --kek-only
    wait_for_migrations
    configure_php agent-api
    exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
    ;;
  horizon)
    wait_for_services
    cache_config
    check_keys --kek-only
    wait_for_migrations
    exec php artisan horizon
    ;;
  reverb)
    wait_for_services
    cache_config
    check_keys --kek-only
    wait_for_migrations
    exec php artisan reverb:start --host=0.0.0.0 --port="${REVERB_SERVER_PORT:-8080}"
    ;;
  scheduler)
    wait_for_services
    cache_config
    check_keys --kek-only
    wait_for_migrations
    exec php artisan schedule:work
    ;;
  relay)
    exec falak-relay
    ;;
  *)
    exec "$@"
    ;;
esac
