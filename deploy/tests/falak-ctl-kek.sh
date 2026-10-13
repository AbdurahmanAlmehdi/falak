#!/usr/bin/env bash
# shellcheck disable=SC2317,SC2329  # stubs (is_running, compose, …) are called by the sourced falak-ctl (SC2317 before shellcheck 0.11)
# Scripted test for the key-encryption key in falak-ctl: `kek init` creates 32 random bytes at secrets/kek once
# (0400, directory 0711) and never replaces it, a wrong-size file stops it, the emergency kit round-trips through
# `kek export` / `kek import`, and the KEK id matches the control plane's. Docker is not needed.
#   deploy/tests/falak-ctl-kek.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
work="$(mktemp -d "${TMPDIR:-/tmp}/falak-ctl-kek.XXXXXX")"
trap 'chmod -R u+w "$work" 2>/dev/null; rm -rf "$work"' EXIT
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$*"; }

export FALAK_DIR="$work/falak" FALAK_CTL_SOURCED=1
mkdir -p "$FALAK_DIR"
printf 'FALAK_DOMAIN=falak.example.com\n' > "$FALAK_DIR/.env"

# shellcheck source=/dev/null
. "$here/../falak-ctl"
is_running() { return 1; }   # no stack here

ensure_kek --quiet
[ -f "$KEK_FILE" ] || fail "no KEK created"
[ "$(file_size "$KEK_FILE")" = 32 ] || fail "KEK size $(file_size "$KEK_FILE")"
[ "$(file_mode "$KEK_FILE")" = 400 ] || fail "KEK mode $(file_mode "$KEK_FILE")"
[ "$(file_mode "$SECRETS_DIR")" = 711 ] || fail "secrets dir mode $(file_mode "$SECRETS_DIR")"
id="$(kek_fingerprint "$KEK_FILE")"
[ "${#id}" = 16 ] || fail "KEK id $id"
pass "kek init creates a 32-byte KEK, mode 0400, in a 0711 directory"

chmod 0644 "$KEK_FILE"
ensure_kek --quiet
[ "$(kek_fingerprint "$KEK_FILE")" = "$id" ] || fail "kek init replaced the KEK"
[ "$(file_mode "$KEK_FILE")" = 400 ] || fail "loose mode not fixed"
pass "kek init keeps an existing KEK and tightens its mode"

if command -v php >/dev/null 2>&1; then
  # shellcheck disable=SC2016  # PHP code
  php_id="$(php -r 'echo substr(hash("sha256", "falak-kek-id:".file_get_contents($argv[1])), 0, 16);' "$KEK_FILE")"
  [ "$php_id" = "$id" ] || fail "falak-ctl id $id != control plane id $php_id"
  pass "the KEK id matches the control plane's (LocalKek::fingerprint)"
fi

out="$(kek_export 2>&1)" || true
grep -q 'usage: falak-ctl kek export <file>' <<<"$out" || fail "export without a file: $out"
grep -q 'BEGIN FALAK KEK' <<<"$out" && fail "export without a file printed the key"
pass "kek export without a file prints usage, never the key"

kit="$work/kit.txt"
kek_export "$kit" >/dev/null
[ "$(file_mode "$kit")" = 600 ] || fail "kit mode $(file_mode "$kit")"
grep -q "^KEK id:    $id\$" "$kit" || fail "kit has no KEK id"
grep -q '^-----BEGIN FALAK KEK-----$' "$kit" || fail "kit has no key block"
(kek_export "$kit" >/dev/null 2>&1) && fail "export overwrote an existing file"
pass "kek export writes a 0600 kit with the KEK id and refuses to overwrite"

# A new host: import the kit.
saved="$FALAK_DIR"
export FALAK_DIR="$work/other"
mkdir -p "$FALAK_DIR"; cp "$saved/.env" "$FALAK_DIR/.env"
# shellcheck disable=SC2034  # read by the sourced falak-ctl functions
{
  ENV_FILE="$FALAK_DIR/.env"
  SECRETS_DIR="$FALAK_DIR/secrets"
  KEK_FILE="$SECRETS_DIR/kek"
  KEK_PREVIOUS="$SECRETS_DIR/kek.previous"
}
kek_import "$kit" >/dev/null
[ "$(kek_fingerprint "$KEK_FILE")" = "$id" ] || fail "imported KEK differs"
[ "$(file_mode "$KEK_FILE")" = 400 ] || fail "imported KEK mode $(file_mode "$KEK_FILE")"
cmp -s "$KEK_FILE" "$saved/secrets/kek" || fail "imported bytes differ"
pass "kek import restores the exact KEK from the kit"

chmod 0600 "$KEK_FILE"; head -c 32 /dev/urandom > "$KEK_FILE"; chmod 0400 "$KEK_FILE"
other="$(kek_fingerprint "$KEK_FILE")"
(kek_import "$kit" >/dev/null 2>&1) && fail "import replaced a different KEK without --force"
[ "$(kek_fingerprint "$KEK_FILE")" = "$other" ] || fail "KEK changed without --force"
kek_import "$kit" --force >/dev/null
[ "$(kek_fingerprint "$KEK_FILE")" = "$id" ] || fail "--force did not import"
ls "$SECRETS_DIR"/kek.replaced-* >/dev/null 2>&1 || fail "the replaced KEK was not kept"
pass "kek import refuses to replace another KEK unless --force, and keeps the old one aside"

sed 's/^KEK id:.*/KEK id:    0000000000000000/' "$kit" > "$work/damaged.txt"
(kek_import "$work/damaged.txt" --force >/dev/null 2>&1) && fail "a kit whose key does not match its id was imported"
pass "kek import checks the key against the kit's KEK id"

chmod 0600 "$KEK_FILE"; head -c 16 /dev/urandom > "$KEK_FILE"
(ensure_kek --quiet >/dev/null 2>&1) && fail "a 16-byte KEK was accepted"
[ "$(file_size "$KEK_FILE")" = 16 ] || fail "kek init replaced a wrong-size KEK"
pass "kek init stops on a wrong-size KEK instead of replacing it"

printf 'FALAK_KEK_PROVIDER=aws-kms\n' >> "$FALAK_DIR/.env"
rm -rf "$SECRETS_DIR"
ensure_kek --quiet
[ ! -e "$SECRETS_DIR" ] || fail "kek init created a file for aws-kms"
pass "kek init does nothing when the KEK is held by KMS / Vault"

# --- helpers around falak:keys:check --json -------------------------------------------------------------
report='{"ok":true,"provider":"local","kek_id":"aaaa","data_keys":3,"stale":2,"failed":0,"kek_ids":["aaaa","bbbb"]}'
[ "$(json_number stale "$report")" = 2 ] || fail "json_number stale"
[ "$(json_number data_keys "$report")" = 3 ] || fail "json_number data_keys"
[ "$(json_kek_ids "$report")" = aaaa,bbbb ] || fail "json_kek_ids: $(json_kek_ids "$report")"
pass "falak:keys:check --json is parsed (stale count, KEK ids)"

# A fresh local install for the rest.
export FALAK_DIR="$work/third"
mkdir -p "$FALAK_DIR/deploy"
: > "$FALAK_DIR/deploy/compose.yml"
printf 'FALAK_DOMAIN=falak.example.com\n' > "$FALAK_DIR/.env"
# shellcheck disable=SC2034  # read by the sourced falak-ctl functions
{
  ENV_FILE="$FALAK_DIR/.env"
  COMPOSE_FILE="$FALAK_DIR/deploy/compose.yml"
  BACKUP_DIR="$FALAK_DIR/backups"
  SECRETS_DIR="$FALAK_DIR/secrets"
  KEK_FILE="$SECRETS_DIR/kek"
  KEK_PREVIOUS="$SECRETS_DIR/kek.previous"
  DR_DIR="$FALAK_DIR/dr"
  DR_FILE="$DR_DIR/dr.env"
  STATE_DIR="$FALAK_DIR/state"
  DR_STATE="$STATE_DIR/dr.state"
  DR_JSON="$STATE_DIR/dr.json"
}
ensure_kek --quiet
current="$(kek_fingerprint "$KEK_FILE")"

# --- kek rotate refuses while data keys still need kek.previous -------------------------------------------
is_running() { return 0; }
keys_check_json() { printf '{"ok":true,"provider":"local","kek_id":"%s","data_keys":2,"stale":1,"failed":0,"kek_ids":["%s","x"]}\n' "$current" "$current"; }
head -c 32 /dev/urandom > "$KEK_PREVIOUS"; chmod 0400 "$KEK_PREVIOUS"
previous="$(kek_fingerprint "$KEK_PREVIOUS")"
out="$( (kek_rotate) 2>&1)" && fail "kek rotate went ahead with a stale data key"
grep -q 'still wrapped by an earlier KEK' <<<"$out" || fail "rotate refusal: $out"
[ "$(kek_fingerprint "$KEK_PREVIOUS")" = "$previous" ] || fail "kek.previous was touched"
[ "$(kek_fingerprint "$KEK_FILE")" = "$current" ] || fail "kek was touched"
ls "$SECRETS_DIR"/kek.retired-* >/dev/null 2>&1 && fail "kek.previous was retired"
keys_check_json() { printf '{"ok":false,"stale":0,"failed":1}\n'; return 1; }
(kek_rotate >/dev/null 2>&1) && fail "kek rotate went ahead after a failed check"
[ "$(kek_fingerprint "$KEK_FILE")" = "$current" ] || fail "kek was touched after a failed check"
pass "kek rotate refuses (touching nothing) while a data key is wrapped by an earlier KEK or the check fails"

# --- restore: a backup without its KEK needs this host to have it ------------------------------------------
bk="$work/backup"; mkdir -p "$bk"
printf 'kek_provider=local\nkek_id=%s\nkek_ids=%s,%s\n' "$current" "$current" "$previous" > "$bk/manifest"
restore_kek_check "$bk" 0 || fail "restore refused although kek and kek.previous are here"
printf 'kek_provider=local\nkek_id=%s\nkek_ids=ffffffffffffffff\n' "$current" > "$bk/manifest"
out="$( (restore_kek_check "$bk" 0) 2>&1)" && fail "restore went ahead without the backup's KEK"
grep -q 'ffffffffffffffff' <<<"$out" || fail "refusal does not name the missing KEK: $out"
(restore_kek_check "$bk" 1 >/dev/null 2>&1) || fail "--force did not let the restore through"
printf 'falak_version=v0.9.0\n' > "$bk/manifest"
restore_kek_check "$bk" 0 || fail "a backup from before v0.10.0 was refused"
mkdir -p "$bk/secrets"; head -c 32 /dev/urandom > "$bk/secrets/kek"
printf 'kek_ids=ffffffffffffffff\n' > "$bk/manifest"
restore_kek_check "$bk" 0 || fail "a backup that carries its KEK was refused"
pass "restore refuses a backup whose KEKs this host lacks (kek and kek.previous checked), unless --force"

# --- backup: KEK provider credentials stay out of unencrypted backups ------------------------------------
printf 'MAIL_HOST=smtp\nFALAK_KEK_AWS_KMS_KEY_ID=alias/falak\nFALAK_KEK_AWS_SECRET_ACCESS_KEY=s3cr3t\nexport FALAK_KEK_VAULT_TOKEN=hvs.x\n' > "$work/custom.env"
copy_env_for_backup "$work/custom.env" "$work/plain.env" "" && fail "nothing reported as left out"
grep -q 's3cr3t\|hvs.x' "$work/plain.env" && fail "credentials in an unencrypted backup"
grep -q '^MAIL_HOST=smtp$' "$work/plain.env" || fail "MAIL_HOST was dropped"
grep -q '^FALAK_KEK_AWS_KMS_KEY_ID=alias/falak$' "$work/plain.env" || fail "the KMS key id was dropped"
copy_env_for_backup "$work/custom.env" "$work/enc.env" "passphrase" || fail "encrypted backup reported a strip"
cmp -s "$work/custom.env" "$work/enc.env" || fail "encrypted backup lost settings"
pass "unencrypted backups leave out FALAK_KEK_AWS_* / FALAK_KEK_VAULT_TOKEN credentials"

# --- backup: a failed encryption leaves no plaintext archive ---------------------------------------------
is_running() { return 0; }
compose() { if [ "${1:-}" = exec ] && [ "${3:-}" = postgres ]; then echo dump; fi; return 0; }
docker() { printf 'x'; }
keys_check_json() { echo '{"ok":true,"kek_ids":[]}'; }
openssl() { return 1; }
dr_set FALAK_BACKUP_PASSPHRASE pw
(cmd_backup --quiet >/dev/null 2>&1) && fail "backup succeeded although encryption failed"
leftover="$(find "$BACKUP_DIR" -name 'falak-backup-*' 2>/dev/null)"
[ -z "$leftover" ] || fail "a failed encryption left $leftover"
pass "a backup whose encryption fails leaves no plaintext archive behind"

unset -f openssl
dr_set FALAK_BACKUP_PASSPHRASE 'a long enough passphrase'
cmd_backup --quiet >/dev/null 2>&1 || fail "backup failed with a working openssl"
enc="$(find "$BACKUP_DIR" -name 'falak-backup-*.fdr')"
[ -n "$enc" ] || fail "no encrypted backup written"
[ -z "$(find "$BACKUP_DIR" -name '*.tmp' -o -name '*.ct')" ] || fail "temporary archives left behind"
FALAK_DR_PASS='a long enough passphrase' dr_open "$enc" "$work/opened.tar.gz"
tar -tzf "$work/opened.tar.gz" | grep -q '^\./secrets/kek$' || fail "the encrypted backup lacks the KEK"
pass "an encrypted backup carries the KEK and leaves no temporary archive"

# --- entrypoint: roles other than web wait for the migrations, every role checks the KEK first -------------
entry="$here/../control-plane/entrypoint.sh"
for role in agent-api horizon reverb scheduler; do
  block="$(awk -v r="  $role)" '$0 == r {on=1; next} on && /^    ;;$/ {exit} on {print}' "$entry")"
  grep -q 'check_keys --kek-only' <<<"$block" || fail "$role does not check the KEK"
  [ "$(grep -n 'wait_for_migrations' <<<"$block" | cut -d: -f1)" -lt "$(grep -n 'exec ' <<<"$block" | head -1 | cut -d: -f1)" ] \
    || fail "$role starts before the migrations ran"
done
grep -q '^zend.exception_ignore_args = On$' "$here/../control-plane/php.ini" || fail "php.ini keeps arguments in stack traces"
pass "the entrypoint makes agent-api, horizon, reverb and scheduler wait for migrations; traces carry no arguments"
