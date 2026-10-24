#!/usr/bin/env bash
# Scripted test for the built-in registry settings in falak-ctl: installs that predate the registry get a host,
# URL and credentials once (never rotated afterwards), custom HTTPS ports end up in the URL, and `domain set`
# moves the host while keeping the old one as an alias. Docker is not needed.
#   deploy/tests/falak-ctl-registry.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
work="$(mktemp -d "${TMPDIR:-/tmp}/falak-ctl-registry.XXXXXX")"
trap 'rm -rf "$work"' EXIT
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$*"; }

export FALAK_DIR="$work/falak" FALAK_CTL_SOURCED=1 FALAK_CRON_DIR="$work/cron.d"
mkdir -p "$FALAK_DIR" "$FALAK_CRON_DIR"
printf 'FALAK_DOMAIN=falak.example.com\nFALAK_AGENT_API_HOST=agents.falak.example.com\n' > "$FALAK_DIR/.env"

# shellcheck source=/dev/null
. "$here/../falak-ctl"

ensure_registry_env >/dev/null
[ "$(env_get FALAK_REGISTRY_HOST)" = registry.falak.example.com ] || fail "host: $(env_get FALAK_REGISTRY_HOST)"
[ "$(env_get FALAK_REGISTRY_URL)" = registry.falak.example.com ] || fail "url: $(env_get FALAK_REGISTRY_URL)"
[ "$(env_get FALAK_REGISTRY_USERNAME)" = falak ] || fail "username: $(env_get FALAK_REGISTRY_USERNAME)"
password="$(env_get FALAK_REGISTRY_PASSWORD)"
[ "${#password}" = 48 ] || fail "password length ${#password}"
[ "$(stat -c %a "$FALAK_DIR/.env" 2>/dev/null || stat -f %Lp "$FALAK_DIR/.env")" = 600 ] || fail ".env is not mode 600"
pass "an install without a registry gets host, URL and credentials"

key="$FALAK_DIR/secrets/registry-token.key" crt="$FALAK_DIR/secrets/registry-token.crt"
[ "$(stat -c %a "$key" 2>/dev/null || stat -f %Lp "$key")" = 400 ] || fail "token key is not mode 400"
[ "$(stat -c %a "$crt" 2>/dev/null || stat -f %Lp "$crt")" = 644 ] || fail "token certificate is not mode 644"
[ "$(openssl x509 -in "$crt" -noout -pubkey)" = "$(openssl pkey -in "$key" -pubout)" ] || fail "certificate does not match the key"
key_sum="$(sha256_hex < "$crt")"
pass "the registry token signing key and its certificate are created"

ensure_registry_env >/dev/null
[ "$(env_get FALAK_REGISTRY_PASSWORD)" = "$password" ] || fail "password rotated on a second run"
[ "$(grep -c '^FALAK_REGISTRY_HOST=' "$FALAK_DIR/.env")" = 1 ] || fail "duplicate FALAK_REGISTRY_HOST lines"
[ "$(sha256_hex < "$crt")" = "$key_sum" ] || fail "token key rotated on a second run"
pass "a second run changes nothing"

# The stack started before the certificate existed: Docker created a directory at its path.
rm -f "$crt"; mkdir "$crt"
ensure_registry_token_key >/dev/null
[ -f "$crt" ] || fail "directory at the certificate path not replaced"
[ "$(openssl x509 -in "$crt" -noout -pubkey)" = "$(openssl pkey -in "$key" -pubout)" ] || fail "new certificate does not match the key"
pass "a directory Docker left at the certificate path is replaced"

printf 'FALAK_DOMAIN=falak.example.com\nFALAK_HTTPS_PORT=8443\n' > "$FALAK_DIR/.env"
ensure_registry_env >/dev/null
[ "$(env_get FALAK_REGISTRY_URL)" = registry.falak.example.com:8443 ] || fail "url with port: $(env_get FALAK_REGISTRY_URL)"
pass "a custom HTTPS port is part of the registry URL"

printf 'FALAK_DOMAIN=falak.example.com\nFALAK_REGISTRY_HOST=images.example.com\nFALAK_REGISTRY_URL=images.example.com\n' > "$FALAK_DIR/.env"
ensure_registry_env >/dev/null
[ "$(env_get FALAK_REGISTRY_HOST)" = images.example.com ] || fail "custom host replaced"
[ -n "$(env_get FALAK_REGISTRY_PASSWORD)" ] || fail "missing password not generated"
pass "a custom registry host is kept; missing credentials are added"

# domain set: the registry follows the panel, the old name stays served as an alias.
printf 'FALAK_DOMAIN=old.example.com\nFALAK_AGENT_API_HOST=agents.old.example.com\nFALAK_REGISTRY_HOST=registry.old.example.com\nFALAK_REGISTRY_URL=registry.old.example.com\nFALAK_REGISTRY_USERNAME=falak\nFALAK_REGISTRY_PASSWORD=secret\n' > "$FALAK_DIR/.env"
require_install() { :; }
cmd_backup() { :; }
wait_healthy() { :; }
health_check() { :; }
cmd_domain set new.example.com >/dev/null
[ "$(env_get FALAK_REGISTRY_HOST)" = registry.new.example.com ] || fail "domain set host: $(env_get FALAK_REGISTRY_HOST)"
[ "$(env_get FALAK_REGISTRY_URL)" = registry.new.example.com ] || fail "domain set url: $(env_get FALAK_REGISTRY_URL)"
[ "$(env_get FALAK_REGISTRY_HOST_ALIASES)" = registry.old.example.com ] || fail "aliases: $(env_get FALAK_REGISTRY_HOST_ALIASES)"
[ "$(env_get FALAK_REGISTRY_PASSWORD)" = secret ] || fail "domain set rotated the password"
pass "domain set moves the registry host and keeps the old one as an alias"

# registry status: custom credentials with " and \ still make a valid curl config (stdin, never argv); the token
# from the token server (panel host) goes to the registry host as a bearer header.
printf 'FALAK_DOMAIN=falak.example.com\nFALAK_REGISTRY_HOST=registry.falak.example.com\nFALAK_REGISTRY_USERNAME=falak\nFALAK_REGISTRY_PASSWORD=pa"ss\\word\n' > "$FALAK_DIR/.env"
edge_curl() {
  cat > "$work/curl-$1.cfg"
  case "$1 $2" in
    "falak.example.com /api/internal/registry/token?service=falak-registry") printf '{"token":"tok.en-1","expires_in":300}' ;;
    "registry.falak.example.com /v2/") printf 200 ;;
    *) printf 'unexpected %s %s' "$1" "$2" ;;
  esac
}
[ "$(registry_code --auth)" = 200 ] || fail "registry_code --auth"
[ "$(cat "$work/curl-registry.falak.example.com.cfg")" = 'header = "Authorization: Bearer tok.en-1"' ] || fail "bearer config: $(cat "$work/curl-registry.falak.example.com.cfg")"
cp "$work/curl-falak.example.com.cfg" "$work/curl.cfg"
[ "$(cat "$work/curl.cfg")" = 'user = "falak:pa\"ss\\word"' ] || fail "curl config: $(cat "$work/curl.cfg")"
if parsed="$(curl -K "$work/curl.cfg" --libcurl - file:///dev/null 2>/dev/null | grep CURLOPT_USERPWD)" && [ -n "$parsed" ]; then
  [ "$parsed" = '  curl_easy_setopt(hnd, CURLOPT_USERPWD, "falak:pa\"ss\\word");' ] || fail "curl parsed: $parsed"
fi
pass "registry credentials are escaped in the curl config"

# registry gc: the registry is started again whatever happens, and a failed collection fails the command.
is_running() { :; }
calls="$work/calls"
gc_mode=ok
idle_mode=idle
compose() {
  echo "$*" >> "$calls"
  case "$1 $idle_mode" in
    "exec busy") echo "1 image build(s) queued or running."; return 1 ;;
    "exec down") echo "service \"control-plane\" is not running" >&2; return 1 ;;
  esac
  case "$1 $gc_mode" in
    "run ok") echo "blob eligible for deletion" ;;
    "run fail") return 1 ;;
    "run killed") me=$(exec sh -c 'echo $PPID'); kill -TERM "$(ps -o ppid= -p "$me" | tr -d " ")"; sleep 1 ;;
  esac
  return 0
}
run_gc() { : > "$calls"; gc_mode="$1"; shift; cmd_registry gc "$@" > "$work/gc.out" 2>&1 && rc=0 || rc=$?; }

run_gc ok
[ "$rc" = 0 ] || fail "gc ok -> $rc"
[ "$(cut -d' ' -f1 "$calls" | tr '\n' ' ')" = "exec stop run start " ] || fail "gc ok calls: $(cat "$calls")"
run_gc fail
[ "$rc" != 0 ] || fail "failed gc exits 0"
[ "$(tail -1 "$calls" | cut -d' ' -f1)" = start ] || fail "gc fail calls: $(cat "$calls")"
run_gc killed
[ "$rc" = 143 ] || fail "killed gc -> $rc"
[ "$(tail -1 "$calls" | cut -d' ' -f1)" = start ] || fail "gc killed calls: $(cat "$calls")"
pass "registry gc restarts the registry after success, failure and interruption, and reports failure"

idle_mode=busy
run_gc ok
[ "$rc" = 0 ] || fail "gc with builds running -> $rc"
[ "$(cut -d' ' -f1-5 "$calls")" = "exec -T control-plane php artisan" ] || fail "gc busy calls: $(cat "$calls")"
grep -q "skipping registry garbage collection: 1 image build(s) queued or running. (tried again next week" "$work/gc.out" || fail "gc busy output: $(cat "$work/gc.out")"
idle_mode=down
run_gc ok
if [ "$rc" != 0 ] || [ "$(wc -l < "$calls" | tr -d ' ')" != 1 ]; then fail "gc with the control plane down -> $rc, calls: $(cat "$calls")"; fi
grep -q 'skipping registry garbage collection: service "control-plane" is not running' "$work/gc.out" || fail "gc down output: $(cat "$work/gc.out")"
idle_mode=busy
run_gc ok --force
[ "$rc" = 0 ] || fail "gc --force -> $rc"
[ "$(cut -d' ' -f1 "$calls" | tr '\n' ' ')" = "stop run start " ] || fail "gc --force calls: $(cat "$calls")"
idle_mode=idle
pass "registry gc is skipped (and says why) while image builds run or the control plane can't tell; --force runs it"

# Weekly garbage collection from cron: installed with the registry settings, kept as is, removed with FALAK_REGISTRY_GC=0.
cron="$FALAK_CRON_DIR/falak-registry-gc"
printf 'FALAK_DOMAIN=falak.example.com\n' > "$FALAK_DIR/.env"
rm -f "$cron"
ensure_registry_env >/dev/null
[ -f "$cron" ] || fail "no cron file"
grep -q "^17 4 \* \* 0 root FALAK_DIR=$FALAK_DIR .*falak-ctl registry gc" "$cron" || fail "cron line: $(cat "$cron")"
before="$(cat "$cron")"
ensure_registry_env >/dev/null
[ "$(cat "$cron")" = "$before" ] || fail "cron file rewritten differently"
env_set FALAK_REGISTRY_GC 0
ensure_registry_env >/dev/null
[ ! -e "$cron" ] || fail "FALAK_REGISTRY_GC=0 kept the cron file"
pass "weekly registry garbage collection is scheduled from cron (FALAK_REGISTRY_GC=0 removes it)"
