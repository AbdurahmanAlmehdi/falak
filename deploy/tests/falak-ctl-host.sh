#!/usr/bin/env bash
# shellcheck disable=SC2317,SC2329,SC2015,SC2016  # stubs (docker, compose, systemctl, setsid …) are called by the sourced falak-ctl
# Scripted test for the host channel and its neighbours in falak-ctl: host-run takes state/host/request.json away and
# runs only `update vX.Y.Z` and `local-server <FALAK_URL>/install/<token>` (bad ids, actions, versions and URLs, a
# symlink and duplicate keys are refused without running anything), writes status.json + <id>.log (0644, newest 20
# kept); update downloads fail closed without SHA256SUMS; ensure-host writes falak-host.path/.service, falakcp.slice and
# (only with a local server) the falak.slice cap; local-server enable/disable write and remove local-edge.env and the
# edge settings. Docker, systemd and the network are stubbed.
#   deploy/tests/falak-ctl-host.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
work="$(mktemp -d "${TMPDIR:-/tmp}/falak-ctl-host.XXXXXX")"
work="$(cd "$work" && pwd -P)"
trap 'rm -rf "$work"' EXIT
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$*"; }

export FALAK_DIR="$work/falak" FALAK_CTL_SOURCED=1 FALAK_SYSTEMD_DIR="$work/systemd" FALAK_LOCAL_EDGE_ENV="$work/etc/local-edge.env"
mkdir -p "$FALAK_DIR/deploy"
printf 'services: {}\n' > "$FALAK_DIR/deploy/compose.yml"
printf 'FALAK_DOMAIN=panel.example.com\nFALAK_URL=https://panel.example.com\nFALAK_VERSION=v0.22.0\n' > "$FALAK_DIR/.env"

# shellcheck source=/dev/null
. "$here/../falak-ctl"

# The job's falak-ctl: records its arguments, exits with $work/stub-rc.
cat > "$work/ctl-stub" <<EOF
#!/bin/sh
printf '%s\n' "\$*" >> "$work/ctl-calls"
echo "stub output for \$1"
exit "\$(cat "$work/stub-rc" 2>/dev/null || echo 0)"
EOF
chmod +x "$work/ctl-stub"
ctl_path() { printf '%s' "$work/ctl-stub"; }
# Detached update: run the job right here (the real ones start a transient unit / a new session).
run_job() { while [ $# -gt 0 ] && [ "$1" != host-job ]; do shift; done; shift; cmd_host_job "$@"; }
systemd-run() { printf 'systemd-run %s\n' "$*" >> "$work/detach"; run_job "$@"; }
setsid() { printf 'setsid %s\n' "$*" >> "$work/detach"; run_job "$@"; }
systemctl_log="$work/systemctl.log"; : > "$systemctl_log"
systemctl() { printf '%s\n' "$*" >> "$systemctl_log"; }
docker() {
  case "$1 ${2:-}" in
    "info --format") printf '%s\n' "${DOCKER_CGROUP_DRIVER:-systemd}" ;;
    "network inspect") case "$*" in *Gateway*) printf '10.213.77.1 10.213.77.0/24\n' ;; *) printf '10.213.77.0/24\n172.30.1.0/24\nfd00:1::/64\n\n%s\n' "${EXTRA_SUBNET:-172.30.2.0/24}" ;; esac ;;
    "network ls") printf 'n1\nn2\nn3\n' ;;
    *) printf 'docker %s\n' "$*" >> "$work/docker-calls" ;;
  esac
}
compose() { printf '%s\n' "$*" >> "$work/compose-calls"; }
mem_total_mb() { printf '8192'; }
cpu_cores() { printf '4'; }

HOST="$FALAK_DIR/state/host"
ID=01JABCDEFGHJKMNPQRSTVWXYZ0
request() { mkdir -p "$HOST"; printf '%s' "$1" > "$HOST/.req.tmp"; mv "$HOST/.req.tmp" "$HOST/request.json"; }
status_field() { sed -n "s/.*\"$1\":\\([^,}]*\\).*/\\1/p" "$HOST/status.json"; }
reset() { rm -f "$work/ctl-calls" "$work/detach" "$HOST/status.json"; }

# --- json_field ---------------------------------------------------------------------------------------------
[ "$(json_field install_url '{"install_url":"https:\/\/a.b\/install\/x"}')" = "https://a.b/install/x" ] || fail "json_field: escaped slashes"
[ "$(json_field id '{ "id" : "abc" ,
 "action":"update"}')" = abc ] || fail "json_field: spacing / newlines"
if json_field id '{"id":"a","id":"b"}' >/dev/null; then fail "json_field: a duplicate key was accepted"; fi
if json_field id '{"id":"a\"b"}' >/dev/null; then fail "json_field: an escaped quote was accepted"; fi
if json_field id '{"action":"x"}' >/dev/null; then fail "json_field: a missing key was accepted"; fi
pass "json_field reads one plain string value (\\/ allowed), refuses duplicates, escapes and missing keys"

# --- good requests ----------------------------------------------------------------------------------------------
reset
request "{\"id\":\"$ID\",\"action\":\"update\",\"version\":\"v0.23.0\",\"requested_by\":\"7\",\"requested_at\":\"2026-10-18T10:00:00Z\"}"
cmd_host_run >/dev/null 2>&1 || fail "host-run failed on a good update request"; wait
[ ! -e "$HOST/request.json" ] || fail "request.json was not taken away"
[ "$(cat "$work/ctl-calls")" = "update --version v0.23.0" ] || fail "update ran as: $(cat "$work/ctl-calls")"
grep -qE "^(systemd-run|setsid) .*host-job $ID update v0.23.0" "$work/detach" || fail "the update was not detached: $(cat "$work/detach" 2>/dev/null)"
[ "$(status_field state)" = '"succeeded"' ] && [ "$(status_field exit_code)" = 0 ] && [ "$(status_field id)" = "\"$ID\"" ] \
  && [ "$(status_field log)" = "\"$ID.log\"" ] && [ "$(status_field action)" = '"update"' ] || fail "status.json: $(cat "$HOST/status.json")"
grep -q 'stub output for update' "$HOST/$ID.log" || fail "the job's output is not in $ID.log"
[ "$(file_mode "$HOST/status.json")" = 644 ] && [ "$(file_mode "$HOST/$ID.log")" = 644 ] || fail "status/log modes"
[ -k "$HOST" ] && [ "$(file_mode "$HOST" | tail -c 4)" = 770 ] || fail "state/host mode $(file_mode "$HOST") (want 1770)"
pass "update vX.Y.Z: taken, detached, run as 'falak-ctl update --version', status succeeded, log 0644, dir 1770"

# Rate limit: another update within 10 minutes of the last one is refused (a rejection doesn't reset the clock).
reset
request "{\"id\":\"$ID\",\"action\":\"update\",\"version\":\"v0.23.1\"}"
cmd_host_run >/dev/null 2>&1 || true; wait
[ ! -e "$work/ctl-calls" ] && [ "$(status_field state)" = '"failed"' ] && grep -q 'less than 10 minutes ago' "$HOST/status.json" || fail "rate limit: $(cat "$HOST/status.json")"
echo $(($(date +%s) - 601)) > "$FALAK_DIR/state/.host-last-update"
reset
request "{\"id\":\"$ID\",\"action\":\"update\",\"version\":\"v0.23.1\"}"
cmd_host_run >/dev/null 2>&1 || true; wait
[ "$(cat "$work/ctl-calls" 2>/dev/null)" = "update --version v0.23.1" ] || fail "an update 10 minutes after the last was refused: $(cat "$HOST/status.json")"
rm -f "$FALAK_DIR/state/.host-last-update"
pass "an update less than 10 minutes after the last one is refused, later it runs"

# Forward only.
for v in v0.22.0 v0.21.9 v0.9.99; do
  reset
  request "{\"id\":\"$ID\",\"action\":\"update\",\"version\":\"$v\"}"
  cmd_host_run >/dev/null 2>&1 || true; wait
  [ ! -e "$work/ctl-calls" ] && grep -q 'is not newer than v0.22.0' "$HOST/status.json" || fail "downgrade to $v: $(cat "$HOST/status.json")"
done
pass "an update to the same or an older version is refused (sort -V: v0.9.99 < v0.22.0)"

# local-server needs root's consent on the host.
reset
ID2=01JABCDEFGHJKMNPQRSTVWXYZ1
request "{\"id\":\"$ID2\",\"action\":\"local-server\",\"install_url\":\"https://panel.example.com/install/abc\"}"
cmd_host_run >/dev/null 2>&1 || true
[ ! -e "$work/ctl-calls" ] && grep -q "not allowed on this host: run 'sudo falak-ctl local-server allow' first" "$HOST/status.json" || fail "local-server without consent: $(cat "$HOST/status.json")"
pass "local-server is refused until root allows it"
env_set FALAK_HOST_ALLOW_LOCAL_SERVER 1
echo $(($(date +%s) - 5)) > "$FALAK_DIR/state/.host-last-update"   # local-server ignores the update rate limit

reset
request "{\"id\":\"$ID2\",\"action\":\"local-server\",\"install_url\":\"https:\\/\\/panel.example.com\\/install\\/abc_DEF-123\"}"
echo 3 > "$work/stub-rc"
cmd_host_run >/dev/null 2>&1 || true
rm -f "$work/stub-rc"
[ "$(cat "$work/ctl-calls")" = "local-server enable --install-url https://panel.example.com/install/abc_DEF-123" ] || fail "local-server ran as: $(cat "$work/ctl-calls")"
[ ! -e "$work/detach" ] || fail "local-server should run in falak-host.service itself"
[ "$(status_field state)" = '"failed"' ] && [ "$(status_field exit_code)" = 3 ] || fail "a failing job: $(cat "$HOST/status.json")"
pass "local-server <FALAK_URL>/install/<token> runs 'local-server enable' (allowed, rate limit exempt); a failing job ends failed with its exit code"
rm -f "$FALAK_DIR/state/.host-last-update"; env_set FALAK_HOST_ALLOW_LOCAL_SERVER 0

# --- refused requests -----------------------------------------------------------------------------------------
refuse() { # refuse LABEL JSON
  reset
  request "$2"
  cmd_host_run >/dev/null 2>&1 || true; wait
  [ ! -e "$HOST/request.json" ] || fail "$1: request.json left behind"
  [ ! -e "$work/ctl-calls" ] || fail "$1: ran $(cat "$work/ctl-calls")"
  [ "$(status_field state)" = '"failed"' ] || fail "$1: status $(cat "$HOST/status.json" 2>/dev/null)"
}
refuse "unknown action" "{\"id\":\"$ID\",\"action\":\"shell\",\"version\":\"v0.23.0\"}"
refuse "no action" "{\"id\":\"$ID\",\"version\":\"v0.23.0\"}"
refuse "two actions" "{\"id\":\"$ID\",\"action\":\"local-server\",\"action\":\"update\",\"version\":\"v0.23.0\"}"
refuse "short id" '{"id":"01JABC","action":"update","version":"v0.23.0"}'
refuse "id with a dash" '{"id":"01JABCDEFGHJKMNPQRSTVWXY-0","action":"update","version":"v0.23.0"}'
refuse "id path" '{"id":"../../../../etc/passwd0000","action":"update","version":"v0.23.0"}'
for v in v0.23 0.23.0 'v0.23.0-rc1' 'v0.23.0; rm -rf /' 'v0.23.0 --skip-backup' '$(id)' ''; do
  refuse "version '$v'" "{\"id\":\"$ID\",\"action\":\"update\",\"version\":\"$v\"}"
done
for u in https://evil.example.com/install/abc https://panel.example.com.evil.com/install/abc http://panel.example.com/install/abc \
  https://panel.example.com/install/ https://panel.example.com/install/../x https://panel.example.com/install/abc?x=1 \
  'https://panel.example.com/install/abc def' https://panel.example.com/install/a/b 'https://panel.example.com/install/abc;id' https://panel.example.com/other/abc; do
  refuse "install_url '$u'" "{\"id\":\"$ID\",\"action\":\"local-server\",\"install_url\":\"$u\"}"
done
pass "unknown/duplicate actions, malformed ids, versions and install URLs are refused; nothing runs"

reset
printf '{"id":"%s","action":"update","version":"v0.23.0"}' "$ID" > "$work/target.json"
ln -s "$work/target.json" "$HOST/request.json"
cmd_host_run >/dev/null 2>&1 || true
[ ! -e "$work/ctl-calls" ] || fail "a symlinked request.json was followed"
[ -f "$work/target.json" ] && [ ! -L "$HOST/request.json" ] || fail "the symlink target was touched / the link left behind"
pass "a symlink at request.json is removed, never followed"

reset
mkdir -p "$HOST/request.json"
cmd_host_run >/dev/null 2>&1 || true
[ ! -e "$HOST/request.json" ] || fail "a directory at request.json was left behind (PathExists would loop)"
pass "anything else at request.json is removed"

# --- log retention ----------------------------------------------------------------------------------------------
for i in $(seq 10 34); do printf 'x\n' > "$HOST/01JOLDLOGOLDLOGOLDLOGOLD$i.log"; touch -t "2026010100$i" "$HOST/01JOLDLOGOLDLOGOLDLOGOLD$i.log"; done
printf 'keep\n' > "$HOST/notes.log"
# Newer logs the panel (uid 33) put there: neither counted nor removed.
for i in 1 2 3; do printf 'panel\n' > "$HOST/01JPANELPANELPANELPANELPA$i.log"; done
file_uid() { case "$1" in *PANELPANEL*) echo 33 ;; *) stat -c %u "$1" 2>/dev/null || stat -f %u "$1" ;; esac; }
rm -f "$FALAK_DIR/state/.host-last-update"
reset
request "{\"id\":\"$ID\",\"action\":\"update\",\"version\":\"v0.23.1\"}"
cmd_host_run >/dev/null 2>&1; wait
n="$(find "$HOST" -name '*.log' | grep -v PANELPANEL | grep -cE '/[0-9A-Za-z]{26}\.log$')"
[ "$n" = 20 ] || fail "kept $n request logs, want 20"
[ -f "$HOST/$ID.log" ] && [ -f "$HOST/notes.log" ] && [ ! -f "$HOST/01JOLDLOGOLDLOGOLDLOGOLD10.log" ] || fail "pruned the wrong logs"
[ "$(find "$HOST" -name '01JPANELPANEL*' | wc -l | tr -d ' ')" = 3 ] || fail "logs not owned by root were removed"
unset -f file_uid
file_uid() { stat -c %u "$1" 2>/dev/null || stat -f %u "$1"; }
pass "the newest 20 request logs are kept; other files and files root does not own are untouched"

# --- SHA256SUMS is mandatory ----------------------------------------------------------------------------------
mkdir -p "$work/rel/deploy" "$work/rel/observability"; printf 'x\n' > "$work/rel/deploy/compose.yml"
tar -czf "$work/falak-deploy.tar.gz" -C "$work/rel" deploy observability
SUMS="$work/sums"
curl() {
  local out="" url="" a
  while [ $# -gt 0 ]; do a="$1"; case "$a" in -o) out="$2"; shift ;; --retry) shift ;; -*) ;; *) url="$a" ;; esac; shift; done
  case "$url" in
    */falak-deploy.tar.gz) cp "$work/falak-deploy.tar.gz" "$out" ;;
    */SHA256SUMS) [ -f "$SUMS" ] || return 22; cp "$SUMS" "$out" ;;
  esac
}
rm -f "$SUMS"
out="$( (fetch_bundle v0.23.0 "$work/stage") 2>&1)" && fail "a bundle without SHA256SUMS was accepted"
grep -q 'refusing to install an unverified bundle' <<<"$out" || fail "no SHA256SUMS: $out"
sum="$(sha256sum "$work/falak-deploy.tar.gz" | cut -d' ' -f1)"
for bad in "$sum  other.tar.gz" "$sum  xfalak-deploy.tar.gz" "$sum  falak-deploy.tar.gz.sig" "$sum  falak-deploy.tar.gz
$sum  falak-deploy.tar.gz" "${sum:0:63}  falak-deploy.tar.gz" "$(printf '%s' "$sum" | tr 'a-f' 'A-F')x  falak-deploy.tar.gz"; do
  printf '%s\n' "$bad" > "$SUMS"
  out="$( (fetch_bundle v0.23.0 "$work/stage") 2>&1)" && fail "SHA256SUMS accepted: $bad"
  grep -q 'no single falak-deploy.tar.gz line' <<<"$out" || fail "SHA256SUMS '$bad': $out"
done
printf '%064d  falak-deploy.tar.gz\n' 0 > "$SUMS"
out="$( (fetch_bundle v0.23.0 "$work/stage") 2>&1)" && fail "a wrong checksum was accepted"
grep -q 'checksum mismatch' <<<"$out" || fail "mismatch: $out"
printf '%s  falak-deploy.tar.gz\n' "$(sha256sum "$work/falak-deploy.tar.gz" | cut -d' ' -f1)" > "$SUMS"
(fetch_bundle v0.23.0 "$work/stage") >/dev/null 2>&1 || fail "a verified bundle was refused"
[ -f "$work/stage/deploy/compose.yml" ] || fail "the verified bundle was not extracted"
env_set FALAK_DEPLOY_SOURCE "$work/rel"; rm -f "$SUMS"
(fetch_bundle v0.23.0 "$work/stage2") >/dev/null 2>&1 || fail "FALAK_DEPLOY_SOURCE needs no SHA256SUMS"
env_set FALAK_DEPLOY_SOURCE ""
unset -f curl
pass "downloaded bundles need SHA256SUMS with exactly one 64-hex falak-deploy.tar.gz line that matches (fail closed); FALAK_DEPLOY_SOURCE is exempt"

# --- ensure-host: units + slices ------------------------------------------------------------------------------
cmd_ensure_host >/dev/null 2>&1 || fail "ensure-host failed"
S="$FALAK_SYSTEMD_DIR"
grep -qx "PathChanged=$HOST/request.json" "$S/falak-host.path" || fail "falak-host.path: $(cat "$S/falak-host.path")"
grep -qx "ExecStart=$work/ctl-stub host-run" "$S/falak-host.service" && grep -qx 'Type=oneshot' "$S/falak-host.service" || fail "falak-host.service"
grep -qx 'enable --now falak-host.path' "$systemctl_log" || fail "falak-host.path not enabled"
grep -qE '^\{"version":"v0.22.0","units":true,"installed_at":"[0-9T:-]+Z","local_server_allowed":false\}$' "$HOST/channel.json" && [ "$(file_mode "$HOST/channel.json")" = 644 ] \
  || fail "channel.json: $(cat "$HOST/channel.json" 2>/dev/null)"
rm -f "$HOST/channel.json"
systemctl() { printf '%s\n' "$*" >> "$systemctl_log"; [ "$1" != enable ]; }
cmd_ensure_host >/dev/null 2>&1 || fail "ensure-host failed when enabling the path unit fails"
[ ! -e "$HOST/channel.json" ] || fail "channel.json written although falak-host.path could not be enabled"
systemctl() { printf '%s\n' "$*" >> "$systemctl_log"; }
grep -qx 'MemoryMin=1536M' "$S/falakcp.slice" && grep -qx 'CPUWeight=1000' "$S/falakcp.slice" && grep -qx 'IOWeight=500' "$S/falakcp.slice" && grep -qx 'ManagedOOMPreference=avoid' "$S/falakcp.slice" || fail "falakcp.slice: $(cat "$S/falakcp.slice")"
[ ! -e "$S/falak.slice.d/50-host-cap.conf" ] || fail "falak.slice cap without a local server"
[ "$(env_get FALAK_CP_SLICE)" = falakcp.slice ] && [ "$(env_get FALAK_BUILDER_CPUS)" = 3 ] && [ -z "$(env_get FALAK_BUILDER_SLICE)" ] || fail "slice env: $(grep SLICE "$ENV_FILE")"
DOCKER_CGROUP_DRIVER=cgroupfs cmd_ensure_host >/dev/null 2>&1 || fail "ensure-host failed with cgroupfs"
grep -qx 'FALAK_CP_SLICE=' "$ENV_FILE" || fail "cgroupfs: FALAK_CP_SLICE should be set empty"
[ "$(env_get FALAK_EDGE_IP)" = 10.213.77.250 ] || fail "FALAK_EDGE_IP: $(env_get FALAK_EDGE_IP)"
[ "$(edge_ip_of 10.20.0.0/16)" = 10.20.0.250 ] && ! edge_ip_of 10.213.77.0/25 >/dev/null && ! edge_ip_of fd00::/64 >/dev/null || fail "edge_ip_of"
(mem_total_mb() { :; }; ensure_slices) >/dev/null 2>&1 && fail "ensure_slices went on without the memory size"
pass "ensure-host: falak-host.path/.service, channel.json, falakcp.slice (MemoryMin, CPUWeight 1000, IOWeight 500, oomd avoid), no falak.slice cap, FALAK_EDGE_IP=.250; cgroupfs skips slices; no MemTotal stops"

for pair in 99:3 0:3 x:3 2:2 3:3; do
  env_set FALAK_BUILDER_CPUS "${pair%%:*}"
  cmd_ensure_host >/dev/null 2>&1 || fail "ensure-host (builder cpus ${pair%%:*})"
  [ "$(env_get FALAK_BUILDER_CPUS)" = "${pair#*:}" ] || fail "FALAK_BUILDER_CPUS ${pair%%:*} became $(env_get FALAK_BUILDER_CPUS), want ${pair#*:}"
done
pass "FALAK_BUILDER_CPUS is recomputed when unset, invalid or above cores - 1; a smaller value stays"

# A stuck "running" update (reboot, killed job) ends failed; one whose unit still runs is left alone.
stuck() { printf '{"id":"%s","action":"update","state":"running","started_at":"2026-10-18T09:00:00Z","finished_at":null,"exit_code":null,"log":"%s.log","error":null}\n' "$ID" "$ID" > "$HOST/status.json"; }
stuck
systemctl() { printf '%s\n' "$*" >> "$systemctl_log"; [ "$1" != is-active ]; }
cmd_host_run >/dev/null 2>&1 || true
[ "$(status_field state)" = '"failed"' ] && grep -q 'interrupted (the host restarted or the job died)' "$HOST/status.json" \
  && [ "$(status_field started_at)" = '"2026-10-18T09:00:00Z"' ] || fail "stuck update not reaped: $(cat "$HOST/status.json")"
stuck
cmd_ensure_host >/dev/null 2>&1 || true
[ "$(status_field state)" = '"failed"' ] || fail "ensure-host did not reap a stuck update"
stuck
systemctl() { printf '%s\n' "$*" >> "$systemctl_log"; }
cmd_host_run >/dev/null 2>&1 || true
[ "$(status_field state)" = '"running"' ] || fail "a running update (unit active) was reaped"
rm -f "$HOST/status.json"
pass "host-run and ensure-host mark a 'running' update failed when its unit is gone; an active unit keeps it running"

# --- local-server enable / disable ----------------------------------------------------------------------------
id() { if [ "${1:-}" = -u ]; then echo 0; else command id "$@"; fi; }
chown() { :; }
edge_curl() { local o=""; printf '%s\n' "$*" > "$work/edge-curl"; while [ $# -gt 0 ]; do [ "$1" = -o ] && o="$2"; shift; done; printf 'echo enrolled > "%s"\n' "$work/enrolled" > "$o"; }
: > "$work/compose-calls"
(cmd_local_server enable --install-url https://evil.example.com/install/abc) >/dev/null 2>&1 && fail "enable took a foreign URL"
cmd_local_server deny >/dev/null 2>&1 || fail "local-server deny"
[ "$(env_get FALAK_HOST_ALLOW_LOCAL_SERVER)" = 0 ] && grep -q '"local_server_allowed":false' "$HOST/channel.json" || fail "deny: $(cat "$HOST/channel.json")"
cmd_local_server allow >/dev/null 2>&1 || fail "local-server allow"
[ "$(env_get FALAK_HOST_ALLOW_LOCAL_SERVER)" = 1 ] && grep -q '"local_server_allowed":true' "$HOST/channel.json" || fail "allow: $(cat "$HOST/channel.json")"
(id() { echo 1000; }; cmd_local_server allow) >/dev/null 2>&1 && fail "allow ran as non-root"
pass "local-server allow/deny (root only) set FALAK_HOST_ALLOW_LOCAL_SERVER and refresh channel.json"
out="$( (DOCKER_CGROUP_DRIVER=cgroupfs; cmd_local_server enable --install-url https://panel.example.com/install/tok_1) 2>&1)" && fail "enable went on with the cgroupfs driver"
grep -q "needs Docker's systemd cgroup driver" <<<"$out" && [ ! -e "$FALAK_LOCAL_EDGE_ENV" ] || fail "cgroupfs refusal: $out"
pass "local-server enable refuses Docker's cgroupfs driver"
cmd_local_server enable --install-url https://panel.example.com/install/tok_1 >/dev/null 2>&1 || fail "local-server enable failed"
want='FALAK_LOCAL_EDGE=1
FALAK_LOCAL_EDGE_ADDR=10.213.77.1
FALAK_LOCAL_EDGE_HTTP_PORT=8080
FALAK_LOCAL_EDGE_HTTPS_PORT=8443
FALAK_LOCAL_EDGE_TRUST=10.213.77.250/32
FALAK_LOCAL_EDGE_PANEL=10.213.77.250
FALAK_LOCAL_EDGE_PANEL_PORTS=80,443
FALAK_LOCAL_EDGE_PROTECT=10.213.77.0/24,172.30.1.0/24,172.30.2.0/24
FALAK_PROJECT_NAME=falak
FALAK_WORKLOAD_SLICE=falak.slice'
[ "$(grep -v '^#' "$FALAK_LOCAL_EDGE_ENV")" = "$want" ] || fail "local-edge.env: $(cat "$FALAK_LOCAL_EDGE_ENV")"
[ "$(file_mode "$FALAK_LOCAL_EDGE_ENV")" = 644 ] || fail "local-edge.env mode"
[ "$(env_get FALAK_LOCAL_EDGE)" = 1 ] && [ "$(env_get FALAK_LOCAL_EDGE_UPSTREAM_HTTPS)" = 10.213.77.1:8443 ] && [ "$(env_get FALAK_LOCAL_EDGE_UPSTREAM_HTTP)" = 10.213.77.1:8080 ] \
  || fail ".env: $(grep LOCAL "$ENV_FILE")"
grep -q 'up -d --wait --wait-timeout 120 --no-deps edge builder' "$work/compose-calls" || fail "the edge was not recreated (and waited for)"
grep -q -- '--retry 10 --retry-connrefused --retry-all-errors --retry-delay 2' "$work/edge-curl" || fail "install script fetch retries: $(cat "$work/edge-curl")"
[ -f "$work/enrolled" ] || fail "the install script did not run"
cap="$S/falak.slice.d/50-host-cap.conf"
grep -qx 'MemoryMax=6656M' "$cap" && grep -qx 'MemoryHigh=6323M' "$cap" && grep -qx 'CPUQuota=300%' "$cap" && grep -qx 'CPUWeight=100' "$cap" \
  && grep -qx 'IOWeight=50' "$cap" && grep -qx "TasksMax=$(workload_tasks_max)" "$cap" || fail "cap: $(cat "$cap")"
[[ "$(workload_tasks_max)" =~ ^[0-9]+$ ]] && [ "$(workload_tasks_max)" -ge 800 ] || fail "TasksMax $(workload_tasks_max)"
dropin="$S/falak-edge.service.d/10-local-edge.conf"
grep -qx 'After=docker.service' "$dropin" && grep -qx 'Wants=docker.service' "$dropin" && grep -qx 'StartLimitIntervalSec=0' "$dropin" && grep -qx 'RestartSec=5' "$dropin" \
  || fail "falak-edge drop-in: $(cat "$dropin" 2>/dev/null)"
[ "$(env_get FALAK_BUILDER_SLICE)" = falak.slice ] || fail "the builder should move to falak.slice"
pass "local-server enable: local-edge.env (PANEL_PORTS), edge upstreams, edge recreated with --wait, fetch retried, install script run, falak.slice capped (memory, CPU, IO, tasks), falak-edge drop-in"

EXTRA_SUBNET=172.30.9.0/24 cmd_ensure_host >/dev/null 2>&1 || fail "ensure-host with a local server"
grep -qx 'FALAK_LOCAL_EDGE_PROTECT=10.213.77.0/24,172.30.1.0/24,172.30.9.0/24' "$FALAK_LOCAL_EDGE_ENV" || fail "ensure-host did not refresh PROTECT: $(cat "$FALAK_LOCAL_EDGE_ENV")"
grep -q 'FALAK_LOCAL_EDGE_PROTECT=10.213.77.0/24,172.30.1.0/24,172.30.9.0/24' <<<"$(cmd_local_server status 2>&1)" || fail "status does not show PROTECT"
cgroup_recursiveprot() { return 1; }
grep -q 'memory_recursiveprot' <<<"$(cmd_local_server status 2>&1)" || fail "status does not warn about memory_recursiveprot"
cgroup_recursiveprot() { return 0; }
pass "ensure-host refreshes local-edge.env (PROTECT) while enabled; status prints it and warns without memory_recursiveprot"

: > "$work/compose-calls"
cmd_local_server disable >/dev/null 2>&1 || fail "local-server disable failed"
[ ! -e "$FALAK_LOCAL_EDGE_ENV" ] && [ -z "$(env_get FALAK_LOCAL_EDGE)" ] && [ -z "$(env_get FALAK_LOCAL_EDGE_UPSTREAM_HTTPS)" ] || fail "disable left settings"
[ ! -e "$cap" ] && [ -z "$(env_get FALAK_BUILDER_SLICE)" ] || fail "disable left the falak.slice cap"
grep -q 'up -d --wait --wait-timeout 120 --no-deps edge builder' "$work/compose-calls" || fail "disable did not recreate the edge"
[ ! -e "$dropin" ] || fail "disable left the falak-edge drop-in"
pass "local-server disable: env file, edge settings and the falak.slice cap go"

# --- update: output also in state/host/update-<UTC>.log; the new falak-ctl writes the host units -----------------
fetch_bundle() { :; }
install_bundle() { printf '#!/bin/sh\necho "new ctl: $*" >> "%s"\n' "$work/new-ctl" > "$FALAK_DIR/deploy/falak-ctl"; }
pull_images() { echo "pulling (stub)"; return 1; }
restore_prev_bundle() { :; }
out="$( (cmd_update --version v0.23.0 --skip-backup) 2>&1)" && fail "the stubbed update should fail at the pull"
sleep 1   # tee flushes after the subshell exits
log="$(find "$HOST" -name 'update-*.log' | head -1)"
[ -n "$log" ] && grep -q 'update v0.22.0 -> v0.23.0' "$log" && grep -q 'pulling (stub)' "$log" || fail "update log: $(cat "$log" 2>/dev/null)"
grep -q 'update v0.22.0 -> v0.23.0' <<<"$out" || fail "update output no longer on stdout"
[ "$(file_mode "$log")" = 644 ] || fail "update log mode"
[ "$(cat "$work/new-ctl")" = "new ctl: ensure-host" ] || fail "the new falak-ctl did not run ensure-host: $(cat "$work/new-ctl" 2>/dev/null)"
pass "update tees its output to state/host/update-<UTC>.log (0644) and runs the new falak-ctl's ensure-host"
