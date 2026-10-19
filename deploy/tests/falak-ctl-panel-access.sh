#!/usr/bin/env bash
# Scripted test for falak-ctl panel-access: `tailnet` refuses (changing nothing) unless the host is on a tailnet with its
# route approved, then sets FALAK_PANEL_ALLOW to the tailnet ranges and recreates the edge; `public` clears it; `status`
# names the mode.
# A failed edge recreate fails the command. Docker and tailscale are stubbed.
#   deploy/tests/falak-ctl-panel-access.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
work="$(mktemp -d "${TMPDIR:-/tmp}/falak-ctl-panel-access.XXXXXX")"
trap 'rm -rf "$work"' EXIT
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$*"; }

export FALAK_DIR="$work/falak" FALAK_CTL_SOURCED=1
mkdir -p "$FALAK_DIR"
printf 'FALAK_DOMAIN=falak.example.com\n' > "$FALAK_DIR/.env"

# shellcheck source=/dev/null
. "$here/../falak-ctl"

require_install() { :; }
calls="$work/calls"
compose_rc=0
compose() { echo "$*" >> "$calls"; return "$compose_rc"; }
tailscale_ip=""
tailscale_routes=""
tailscale() {
  case "$*" in
    "ip -4") [ -n "$tailscale_ip" ] && echo "$tailscale_ip" ;;
    "status --json --peers=false") printf '{\n  "Self": {\n    "PrimaryRoutes": [%s\n    ]\n  }\n}\n' "$tailscale_routes" ;;
    *) return 1 ;;
  esac
}
run() { (cmd_panel_access "$@") > "$work/out" 2>&1; }

if run tailnet; then fail "tailnet without a tailnet succeeded"; fi
grep -q 'Settings → Network → Tailscale' "$work/out" || fail "no join hint: $(cat "$work/out")"
[ -z "$(env_get FALAK_PANEL_ALLOW)" ] || fail "FALAK_PANEL_ALLOW set although refused"
[ ! -s "$calls" ] || fail "edge recreated although refused: $(cat "$calls")"
if ! { run status && grep -q 'panel access: public' "$work/out"; }; then fail "status: $(cat "$work/out")"; fi
pass "tailnet refuses off the tailnet and changes nothing; status says public"

tailscale_ip=100.101.102.103
if run tailnet; then fail "tailnet with an unapproved route succeeded"; fi
grep -q 'Approve it in the Tailscale admin console' "$work/out" || fail "no approval hint: $(cat "$work/out")"
if [ -n "$(env_get FALAK_PANEL_ALLOW)" ] || [ -s "$calls" ]; then fail "changed although the route is not approved"; fi
pass "tailnet refuses while the route awaits approval and changes nothing"

tailscale_routes='
      "203.0.113.5/32"'
run tailnet || fail "tailnet: $(cat "$work/out")"
[ "$(env_get FALAK_PANEL_ALLOW)" = "100.64.0.0/10 fd7a:115c:a1e0::/48" ] || fail "FALAK_PANEL_ALLOW: $(env_get FALAK_PANEL_ALLOW)"
[ "$(cat "$calls")" = "up -d --wait --wait-timeout 120 --no-deps edge" ] || fail "compose: $(cat "$calls")"
[ "$(stat -c %a "$FALAK_DIR/.env" 2>/dev/null || stat -f %Lp "$FALAK_DIR/.env")" = 600 ] || fail ".env is not mode 600"
if ! { run status && grep -q 'panel access: tailnet' "$work/out" && grep -q '100.101.102.103' "$work/out"; }; then fail "status: $(cat "$work/out")"; fi
pass "tailnet sets the tailnet ranges and recreates the edge; status says tailnet"

: > "$calls"
run public || fail "public: $(cat "$work/out")"
[ -z "$(env_get FALAK_PANEL_ALLOW)" ] || fail "FALAK_PANEL_ALLOW not cleared"
[ "$(grep -c '^FALAK_PANEL_ALLOW=' "$FALAK_DIR/.env")" = 1 ] || fail "duplicate FALAK_PANEL_ALLOW lines"
[ "$(cat "$calls")" = "up -d --wait --wait-timeout 120 --no-deps edge" ] || fail "compose: $(cat "$calls")"
pass "public clears FALAK_PANEL_ALLOW and recreates the edge"

compose_rc=1
if run tailnet; then fail "a failed edge recreate succeeded"; fi
grep -q 'recreating the edge failed' "$work/out" || fail "no recreate error: $(cat "$work/out")"
env_set FALAK_PANEL_ALLOW "10.0.0.0/8"
if ! { run status && grep -q 'panel access: custom (10.0.0.0/8)' "$work/out"; }; then fail "status custom: $(cat "$work/out")"; fi
if run bogus; then fail "unknown subcommand accepted"; fi
pass "a failed edge recreate fails; status shows a custom list; unknown subcommands are refused"
