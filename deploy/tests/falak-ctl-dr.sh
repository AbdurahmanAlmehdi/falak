#!/usr/bin/env bash
# shellcheck disable=SC2317,SC2329  # stubs (curl, compose, docker, systemctl …) are called by the sourced falak-ctl (SC2317 before shellcheck 0.11)
# Scripted test for disaster recovery in falak-ctl and install.sh: `dr setup` writes dr/dr.env (0600 in a 0700
# directory) and the backup / drill timers, never prints a secret and refuses secrets on the command line; uploads
# are refused without the DR passphrase; s3://latest and s3://NAME resolve through a fake S3 (paged listing, SHA-256
# check, keys never on curl's command line); a drill runs in its own compose project (falak-drill) with its own
# directory, ports and subnet and is torn down; dr.json carries no secrets; install.sh parses --restore-from.
# Docker, systemd and S3 are stubbed.
#   deploy/tests/falak-ctl-dr.sh
set -euo pipefail
export LC_ALL=C   # sed over the binary test fixtures

here="$(cd "$(dirname "$0")" && pwd)"
work="$(mktemp -d "${TMPDIR:-/tmp}/falak-ctl-dr.XXXXXX")"
trap 'chmod -R u+w "$work" 2>/dev/null; rm -rf "$work"' EXIT
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$*"; }

export FALAK_DIR="$work/falak" FALAK_CTL_SOURCED=1 FALAK_SYSTEMD_DIR="$work/systemd"
mkdir -p "$FALAK_DIR/deploy" "$FALAK_DIR/observability"
printf 'services:\n  postgres:\n    restart: unless-stopped\n' > "$FALAK_DIR/deploy/compose.yml"
printf 'FALAK_DOMAIN=falak.example.com\nFALAK_VERSION=v0.10.0\nFALAK_IMAGE_PREFIX=ghcr.io/x\nFALAK_EDGE_SUBNET=10.213.77.0/24\nDB_PASSWORD=db\n' > "$FALAK_DIR/.env"
unset FALAK_BACKUP_PASSPHRASE FALAK_BACKUP_S3_ENDPOINT FALAK_BACKUP_S3_BUCKET FALAK_BACKUP_S3_SECRET_KEY FALAK_BACKUP_S3_ACCESS_KEY

# shellcheck source=/dev/null
. "$here/../falak-ctl"
is_running() { return 1; }
systemctl_log="$work/systemctl.log"; : > "$systemctl_log"
systemctl() { printf '%s\n' "$*" >> "$systemctl_log"; }

# --- a fake S3: objects under $work/s3/<bucket>/<key>; listings page two keys at a time ---------------------
S3="$work/s3"; mkdir -p "$S3/dr-bucket"
curl_argv="$work/curl-argv"; : > "$curl_argv"
curl() {
  local out="" upload="" url="" arg
  printf '%s\n' "$*" >> "$curl_argv"
  while [ $# -gt 0 ]; do
    arg="$1"
    case "$arg" in
      -o) out="$2"; shift ;;
      -T) upload="$2"; shift ;;
      -K) cat > /dev/null; shift ;;
      -H|--aws-sigv4|--retry|--user) shift ;;
      -*) ;;
      *) url="$arg" ;;
    esac
    shift
  done
  local path="${url#https://s3.example.com/}" query=""
  case "$path" in *\?*) query="${path#*\?}"; path="${path%%\?*}" ;; esac
  if [ -n "$upload" ]; then mkdir -p "$(dirname "$S3/$path")"; cp "$upload" "$S3/$path"; return 0; fi
  if [ -n "$query" ]; then # ListObjectsV2
    local bucket="${path%/}" prefix token keys start=0
    prefix="$(printf '%s' "$query" | sed -n 's/.*prefix=\([^&]*\).*/\1/p' | sed 's/%2F/\//g')"
    token="$(printf '%s' "$query" | sed -n 's/.*continuation-token=\([^&]*\).*/\1/p')"
    [ -n "$token" ] && start="${token#page}"
    keys="$(if cd "$S3/$bucket"; then find . -type f | sed 's#^\./##' | { grep "^$prefix" || true; } | sort; fi)"
    printf '<ListBucketResult>'
    printf '%s\n' "$keys" | sed -n "$((start + 1)),$((start + 2))p" | while read -r k; do [ -n "$k" ] && printf '<Contents><Key>%s</Key></Contents>' "$k"; done
    if [ "$(printf '%s\n' "$keys" | grep -c .)" -gt $((start + 2)) ]; then printf '<NextContinuationToken>page%s</NextContinuationToken>' $((start + 2)); fi
    printf '</ListBucketResult>\n'
    return 0
  fi
  [ -f "$S3/$path" ] || return 22
  cp "$S3/$path" "$out"
}

# --- dr setup ------------------------------------------------------------------------------------------------
secret_file="$work/secret"; printf 'S3CRET-KEY-123\n' > "$secret_file"
pass_file="$work/pass"; printf 'correct horse battery staple\n' > "$pass_file"
out="$( (dr_setup --yes --endpoint https://s3.example.com/ --bucket dr-bucket --access-key AKIA1 --secret-key-file "$secret_file" --every 4) 2>&1)" \
  && fail "dr setup went ahead without a passphrase"
grep -q 'passphrase is required' <<<"$out" || fail "refusal without passphrase: $out"
[ ! -f "$FALAK_SYSTEMD_DIR/falak-backup.timer" ] || fail "a timer was installed without a passphrase"
printf 'short\n' > "$work/short"
(dr_setup --yes --endpoint https://s3.example.com --bucket dr-bucket --access-key AKIA1 --secret-key-file "$secret_file" --passphrase-file "$work/short" >/dev/null 2>&1) \
  && fail "a 5-character passphrase was accepted"
out="$( (dr_setup --yes --passphrase hunter2) 2>&1)" && fail "--passphrase on the command line was accepted"
grep -q 'process list' <<<"$out" || fail "--passphrase refusal: $out"
pass "dr setup refuses to go on without a passphrase (12+ characters) and refuses secrets on the command line"

out="$(dr_setup --yes --endpoint https://s3.example.com/ --bucket dr-bucket --region eu-central-1 --access-key AKIA1 \
  --secret-key-file "$secret_file" --passphrase-file "$pass_file" --every 4 --drill monthly 2>&1)" || fail "dr setup failed: $out"
[ "$(file_mode "$DR_DIR")" = 700 ] || fail "dr dir mode $(file_mode "$DR_DIR")"
[ "$(file_mode "$DR_FILE")" = 600 ] || fail "dr.env mode $(file_mode "$DR_FILE")"
grep -q '^FALAK_BACKUP_PASSPHRASE=correct horse battery staple$' "$DR_FILE" || fail "passphrase not saved"
grep -q '^FALAK_BACKUP_S3_SECRET_KEY=S3CRET-KEY-123$' "$DR_FILE" || fail "secret key not saved"
grep -q '^FALAK_BACKUP_S3_ENDPOINT=https://s3.example.com$' "$DR_FILE" || fail "endpoint not normalised"
grep -q 'FALAK_BACKUP_' "$FALAK_DIR/.env" && fail "DR settings leaked into .env"
grep -q 'S3CRET-KEY-123\|correct horse' <<<"$out" && fail "dr setup printed a secret"
grep -q 'S3CRET-KEY-123' "$curl_argv" && fail "the secret key reached curl's command line"
[ -f "$S3/dr-bucket/falak/.falak-dr-check" ] || fail "the bucket was not tested"
pass "dr setup writes dr/dr.env (dir 0700, file 0600), tests the bucket, prints no secret, keeps keys off curl's argv"

grep -q '^OnCalendar=\*-\*-\* 00/4:00:00$' "$FALAK_SYSTEMD_DIR/falak-backup.timer" || fail "backup timer calendar: $(cat "$FALAK_SYSTEMD_DIR/falak-backup.timer")"
grep -q '^Persistent=true$' "$FALAK_SYSTEMD_DIR/falak-backup.timer" || fail "backup timer is not persistent"
grep -q "^ExecStart=.* backup --upload --scheduled" "$FALAK_SYSTEMD_DIR/falak-backup.service" || fail "backup service ExecStart"
grep -q "^Environment=FALAK_DIR=$FALAK_DIR FALAK_PROJECT=falak$" "$FALAK_SYSTEMD_DIR/falak-backup.service" || fail "backup service environment"
grep -q '^ExecStart=.* dr drill --scheduled$' "$FALAK_SYSTEMD_DIR/falak-drill.service" || fail "drill service"
grep -q '^OnCalendar=\*-\*-01 ' "$FALAK_SYSTEMD_DIR/falak-drill.timer" || fail "drill timer is not monthly"
grep -q 'enable --now falak-backup.timer' "$systemctl_log" || fail "backup timer not enabled: $(cat "$systemctl_log")"
grep -q 'enable --now falak-drill.timer' "$systemctl_log" || fail "drill timer not enabled"
grep -q 'PASSPHRASE\|S3CRET' "$FALAK_SYSTEMD_DIR"/falak-* && fail "a unit file holds a secret"
dr_set FALAK_DR_DRILL off; dr_install_timers >/dev/null 2>&1
[ ! -f "$FALAK_SYSTEMD_DIR/falak-drill.timer" ] || fail "--drill off kept the drill timer"
dr_set FALAK_DR_DRILL monthly; dr_install_timers >/dev/null 2>&1
[ "$(dr_calendar 24)" = '*-*-* 03:00:00' ] || fail "daily calendar $(dr_calendar 24)"
(dr_setup --yes --every 5 >/dev/null 2>&1) && fail "--every 5 was accepted"
pass "dr setup installs falak-backup.timer (every N h, persistent) and falak-drill.timer (monthly), without secrets"

dr_configured || fail "not configured after dr setup"
json="$(dr_status --json)"
grep -q '"configured":true' <<<"$json" || fail "dr.json: $json"
grep -q '"schedule_hours":4' <<<"$json" || fail "dr.json schedule: $json"
grep -q '"target":"s3://dr-bucket/falak"' <<<"$json" || fail "dr.json target: $json"
grep -q 'S3CRET\|correct horse\|AKIA1' "$DR_JSON" && fail "dr.json holds a secret"
[ "$(file_mode "$DR_JSON")" = 644 ] || fail "dr.json mode $(file_mode "$DR_JSON")"
if command -v python3 >/dev/null 2>&1; then python3 -c 'import json,sys; json.load(open(sys.argv[1]))' "$DR_JSON" || fail "dr.json is not JSON"; fi
pass "dr status --json reports configured, schedule and target; dr.json (0644) holds no secret"

# --- uploads are encrypted or nothing ----------------------------------------------------------------------
mv "$DR_FILE" "$work/dr.env.saved"
dr_set FALAK_BACKUP_S3_ENDPOINT https://s3.example.com; dr_set FALAK_BACKUP_S3_BUCKET dr-bucket
mkdir -p "$BACKUP_DIR"
out="$( (cmd_backup --upload) 2>&1)" && fail "backup --upload went ahead without a passphrase"
grep -q 'needs the DR passphrase' <<<"$out" || fail "--upload refusal: $out"
[ -z "$(find "$BACKUP_DIR" -name 'falak-backup-*')" ] || fail "a refused upload still wrote a backup"
printf 'x' > "$work/falak-backup-plain.tar.gz"
(s3_upload "$work/falak-backup-plain.tar.gz" 1 >/dev/null 2>&1) && fail "an unencrypted backup was uploaded (--upload)"
s3_upload "$work/falak-backup-plain.tar.gz" 0 >/dev/null 2>&1 || fail "the automatic upload failed the backup"
[ ! -f "$S3/dr-bucket/falak/falak-backup-plain.tar.gz" ] || fail "an unencrypted backup left the host"
cmd_backup_scheduled --upload --scheduled >/dev/null 2>&1 && fail "a scheduled backup without passphrase succeeded"
grep -q '^failure_error=.*passphrase' "$DR_STATE" || fail "the scheduled failure was not recorded: $(cat "$DR_STATE")"
grep -q '"last_failure":{"at":"' "$DR_JSON" || fail "dr.json has no failure"
mv "$work/dr.env.saved" "$DR_FILE"
pass "uploads are refused without the DR passphrase and never send a plaintext backup; scheduled failures are recorded"

# --- authenticated encryption -------------------------------------------------------------------------------------
# RFC 4231 test case 2 (key "Jefe"), against this HMAC built from shell builtins.
[ "$(printf 'what do ya want for nothing?' | hmac_sha256 4a656665)" = 5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843 ] \
  || fail "hmac_sha256 does not match RFC 4231"
key="$(FALAK_DR_PASS='correct horse battery staple' dr_kdf 0011223344556677)"
[ "${#key}" = 64 ] || fail "dr_kdf: $key"
[ "$(FALAK_DR_PASS='correct horse battery staple' dr_kdf 0011223344556677)" = "$key" ] || fail "dr_kdf is not deterministic"
[ "$(FALAK_DR_PASS='other passphrase here' dr_kdf 0011223344556677)" != "$key" ] || fail "dr_kdf ignores the passphrase"
printf 'archive bytes' > "$work/plain.bin"
FALAK_DR_PASS='correct horse battery staple' dr_seal "$work/plain.bin" "$work/sealed.fdr" falak-backup-x 20261010T000000Z
is_sealed "$work/sealed.fdr" || fail "no FALAK-DR-BACKUP header"
grep -q 'archive bytes' "$work/sealed.fdr" && fail "the sealed file holds the plaintext"
[ "$(dr_header "$work/sealed.fdr" kdf)" = pbkdf2-sha256-600000 ] || fail "kdf header"
[ "$(head -n 1 "$work/sealed.fdr")" = "FALAK-DR-BACKUP 3" ] || fail "format version"
FALAK_DR_PASS='correct horse battery staple' dr_open "$work/sealed.fdr" "$work/opened.bin" falak-backup-x
cmp -s "$work/plain.bin" "$work/opened.bin" || fail "seal/open round trip"
(FALAK_DR_PASS='wrong passphrase!!' dr_open "$work/sealed.fdr" "$work/o2" >/dev/null 2>&1) && fail "a wrong passphrase opened it"
(FALAK_DR_PASS='correct horse battery staple' dr_open "$work/sealed.fdr" "$work/o2" falak-backup-y >/dev/null 2>&1) && fail "another name was accepted"
cp "$work/sealed.fdr" "$work/flip.fdr"; printf 'X' | dd of="$work/flip.fdr" bs=1 seek=$(($(wc -c < "$work/flip.fdr") - 3)) conv=notrunc 2>/dev/null
out="$( (FALAK_DR_PASS='correct horse battery staple' dr_open "$work/flip.fdr" "$work/o3") 2>&1)" && fail "a changed ciphertext was decrypted"
grep -q 'failed authentication' <<<"$out" || fail "tamper message: $out"
[ ! -s "$work/o3" ] || fail "a tampered file was decrypted before the MAC check"
sed 's/^mac: .*/mac: /' "$work/sealed.fdr" > "$work/nomac.fdr"
(FALAK_DR_PASS='correct horse battery staple' dr_open "$work/nomac.fdr" "$work/o4" >/dev/null 2>&1) && fail "a missing MAC was accepted"
sed 's/^created_at: .*/created_at: 20991231T000000Z/' "$work/sealed.fdr" > "$work/hdr.fdr"
(FALAK_DR_PASS='correct horse battery staple' dr_open "$work/hdr.fdr" "$work/o5" >/dev/null 2>&1) && fail "a changed header was accepted"
pass "backups are encrypt-then-MAC (PBKDF2 600k, HMAC-SHA256 checked before decrypting); tampering, a wrong passphrase, a missing MAC or another name fail"

# Every header line is bound or checked; the file is parsed by position; truncations and insertions fail.
P='correct horse battery staple'
refused() { # refused FILE WHAT : dr_open must refuse it without writing any plaintext
  rm -f "$work/r.out"
  if (FALAK_DR_PASS="$P" dr_open "$1" "$work/r.out" >/dev/null 2>&1); then fail "$2 was accepted"; fi
  [ ! -s "$work/r.out" ] || fail "$2 was decrypted"
}
sed 's/^name: .*/name: falak-backup-y/' "$work/sealed.fdr" > "$work/t1.fdr"; refused "$work/t1.fdr" "a changed name: line"
sed 's/^mac_salt: .*/mac_salt: 0011223344556677/' "$work/sealed.fdr" > "$work/t2.fdr"; refused "$work/t2.fdr" "a changed mac_salt"
sed 's/^kdf: .*/kdf: pbkdf2-sha256-1000/' "$work/sealed.fdr" > "$work/t3.fdr"; refused "$work/t3.fdr" "a weaker kdf line"
sed '1s/.*/FALAK-DR-BACKUP 2/' "$work/sealed.fdr" > "$work/t4.fdr"; refused "$work/t4.fdr" "another version line"
size="$(wc -c < "$work/sealed.fdr" | tr -d ' ')"
head -c $((size - 16)) "$work/sealed.fdr" > "$work/t5.fdr"; refused "$work/t5.fdr" "a ciphertext cut by a block"
head -c $((size - 5)) "$work/sealed.fdr" > "$work/t6.fdr"; refused "$work/t6.fdr" "a ciphertext cut mid-block"
head -c 60 "$work/sealed.fdr" > "$work/t7.fdr"; refused "$work/t7.fdr" "a file cut inside its header"
head -n 7 "$work/sealed.fdr" > "$work/t8.fdr"; refused "$work/t8.fdr" "a file without ciphertext"
{ head -n 7 "$work/sealed.fdr"; printf 'extra\n'; tail -c +"$(($(head -n 7 "$work/sealed.fdr" | wc -c) + 1))" "$work/sealed.fdr"; } > "$work/t9.fdr"
refused "$work/t9.fdr" "an extra line before the ciphertext"
{ head -n 6 "$work/sealed.fdr"; printf 'junk\n'; tail -c +"$(($(head -n 7 "$work/sealed.fdr" | wc -c) + 1))" "$work/sealed.fdr"; } > "$work/t10.fdr"
refused "$work/t10.fdr" "a non-empty line 7"
{ head -n 1 "$work/sealed.fdr"; printf 'note: x\n'; tail -n +2 "$work/sealed.fdr"; } > "$work/t11.fdr"
refused "$work/t11.fdr" "a header with a line inserted"
[ "$(dr_header "$work/t11.fdr" name)" = "" ] || fail "dr_header did not parse by position"
# The MAC key is labelled (domain-separated) and its salt never the ciphertext's.
salt="$(dr_header "$work/sealed.fdr" mac_salt)"
[ "$(FALAK_DR_PASS="$P" dr_mac_key "$salt")" != "$(FALAK_DR_PASS="$P" dr_kdf "$salt")" ] || fail "the MAC key is the raw KDF output"
[ "$salt" != "$(ct_salt "$work/sealed.fdr" "$(head -n 7 "$work/sealed.fdr" | wc -c | tr -d ' ')")" ] || fail "MAC salt equals the ciphertext salt"
grep -q 'the MAC salt is the ciphertext' "$here/../falak-ctl" || fail "no check that the salts differ"
# Copy first: a local file swapped after the MAC check is never what gets decrypted.
FALAK_DR_PASS="$P" dr_seal "$work/plain.bin" "$work/swap.fdr" falak-backup-x 20261010T000000Z
printf 'evil archive' > "$work/evil.bin"
FALAK_DR_PASS='an attacker passphrase' dr_seal "$work/evil.bin" "$work/evil.fdr" falak-backup-x 20261010T000000Z
hmac_sha256() { local out; out="$(command_hmac "$@")"; cp "$work/evil.fdr" "$work/swap.fdr"; printf '%s' "$out"; }
command_hmac() { # the real HMAC, from a copy of its definition
  local key="$1" ipad="" opad="" i b x inner
  for ((i = 0; i < 64; i++)); do b=0; if [ $((i * 2)) -lt ${#key} ]; then b=$((16#${key:i*2:2})); fi
    printf -v x '\\x%02x' $((b ^ 0x36)); ipad+="$x"; printf -v x '\\x%02x' $((b ^ 0x5c)); opad+="$x"; done
  # shellcheck disable=SC2059
  inner="$({ printf "$ipad"; cat; } | openssl dgst -sha256 -binary | hex_of)"
  # shellcheck disable=SC2059
  { printf "$opad"; printf "$(printf '%s' "$inner" | sed 's/../\\x&/g')"; } | openssl dgst -sha256 -binary | hex_of
}
rm -f "$work/swap.out"
(FALAK_DR_PASS="$P" dr_open "$work/swap.fdr" "$work/swap.out" >/dev/null 2>&1) || fail "the copy was not what got opened"
cmp -s "$work/plain.bin" "$work/swap.out" || fail "a file swapped during the check was decrypted"
cmp -s "$work/swap.fdr" "$work/evil.fdr" || fail "the swap did not happen (test broken)"
unset -f hmac_sha256 command_hmac
# shellcheck source=/dev/null
. <(sed -n '/^hmac_sha256() {/,/^}/p' "$here/../falak-ctl")
pass "the header is parsed by position; every line, truncations and insertions are caught; the MAC key is labelled; a swapped local file can't bypass the MAC (copy first)"

# --- s3://latest and s3://NAME ----------------------------------------------------------------------------------
mk_backup() { # mk_backup NAME [UPLOAD_AS] : an authenticated fake backup in the bucket
  local name="$1" d="$work/mk.$1"; mkdir -p "$d"
  printf 'dump' > "$d/db.dump"; printf 'FALAK_DOMAIN=falak.example.com\nDB_PASSWORD=db\n' > "$d/env"
  printf 'falak_version=v0.10.0\nbackup_name=%s\ncreated_at=%s\nencrypted=1\nrows=users:2\n' "$name" "${name#falak-backup-}" > "$d/manifest"
  tar -C "$d" -czf "$work/ca.tar.gz" manifest; mv "$work/ca.tar.gz" "$d/falak-ca.tar.gz"
  tar -C "$d" -czf "$work/$name.tar.gz" .
  FALAK_DR_PASS='correct horse battery staple' dr_seal "$work/$name.tar.gz" "$work/$name.fdr" "$name" "${name#falak-backup-}"
  s3_upload "$work/$name.fdr" 1 >/dev/null
}
for ts in 20261001T000000Z 20261003T000000Z 20261002T000000Z 20261004T060000Z; do mk_backup "falak-backup-$ts"; done
# Plaintext and old-format objects a bucket writer could drop there: newer by name, never picked.
printf 'evil' > "$S3/dr-bucket/falak/falak-backup-20991231T000000Z.tar.gz"
printf 'evil' > "$S3/dr-bucket/falak/falak-backup-20991230T000000Z.tar.gz.enc"
[ "$(s3_list "falak/falak-backup-" | grep -c fdr)" = 4 ] || fail "paged listing: $(s3_list "falak/falak-backup-")"
[ "$(s3_backup_key latest)" = falak/falak-backup-20261004T060000Z.fdr ] || fail "latest: $(s3_backup_key latest)"
[ "$(s3_backup_key falak-backup-20261002T000000Z)" = falak/falak-backup-20261002T000000Z.fdr ] || fail "name without extension"
[ "$(s3_backup_key falak-backup-20261001T000000Z.fdr)" = falak/falak-backup-20261001T000000Z.fdr ] || fail "name with extension"
(s3_backup_key falak-backup-20991231T000000Z.tar.gz >/dev/null 2>&1) && fail "a plaintext object was accepted by name"
(s3_backup_key falak-backup-20991230T000000Z.tar.gz.enc >/dev/null 2>&1) && fail "an unauthenticated object was accepted by name"
(s3_backup_key falak-backup-19990101T000000Z >/dev/null 2>&1) && fail "an unknown name resolved"
(s3_backup_key ../other/falak-backup-x >/dev/null 2>&1) && fail "a path was accepted"
pass "s3://latest picks the newest authenticated backup (.fdr) over paged listings, never a plaintext or old-format object"

got="$(restore_source s3://latest 2>/dev/null)"
[ "$got" = "$BACKUP_DIR/falak-backup-20261004T060000Z.fdr" ] || fail "restore_source: $got"
[ "$(file_mode "$got")" = 600 ] || fail "downloaded backup mode $(file_mode "$got")"
x="$work/x"; mkdir -p "$x"; extract_backup "$got" "$x" falak-backup-20261004T060000Z; [ -s "$x/db.dump" ] || fail "downloaded backup does not extract"
# A replayed object: an older (authentic) backup copied over the newest key fails the name binding.
cp "$S3/dr-bucket/falak/falak-backup-20261001T000000Z.fdr" "$S3/dr-bucket/falak/falak-backup-20261004T060000Z.fdr"
rm -f "$got"; got="$(restore_source s3://latest 2>/dev/null)"
out="$( (extract_backup "$got" "$work/x2" "$(basename "$got" .fdr)") 2>&1)" && fail "a replayed object was accepted"
grep -q "is backup 'falak-backup-20261001T000000Z'" <<<"$out" || fail "replay message: $out"
# A manifest that says it is not encrypted (or names another backup) inside an authentic file is refused too.
d="$work/mk.bad"; mkdir -p "$d"; printf 'dump' > "$d/db.dump"; : > "$d/env"
printf 'backup_name=falak-backup-20261005T000000Z\ncreated_at=20261005T000000Z\nencrypted=0\n' > "$d/manifest"
tar -C "$d" -czf "$work/bad.tar.gz" .
FALAK_DR_PASS='correct horse battery staple' dr_seal "$work/bad.tar.gz" "$work/falak-backup-20261005T000000Z.fdr" falak-backup-20261005T000000Z 20261005T000000Z
mkdir -p "$work/x3"; (extract_backup "$work/falak-backup-20261005T000000Z.fdr" "$work/x3" >/dev/null 2>&1) && fail "a manifest without encrypted=1 was accepted"
mk_backup falak-backup-20261004T060000Z
rm -f "$BACKUP_DIR"/falak-backup-*; got="$(restore_source s3://latest 2>/dev/null)"
(restore_source s3:// >/dev/null 2>&1) && fail "s3:// without a name was accepted"
(cmd_restore s3://latest >/dev/null 2>&1 </dev/null) && fail "restore without --yes went ahead"
grep -q 'S3CRET-KEY-123' "$curl_argv" && fail "the secret key reached curl's command line"
pass "restore s3://… downloads into backups/ (0600), binds the object name, checks the manifest and needs --yes"

# Old local formats still restore, with a warning; never from the bucket.
mkdir -p "$work/x4"; out="$(extract_backup "$work/falak-backup-20261004T060000Z.tar.gz" "$work/x4" 2>&1)" || fail "a local plain backup was refused: $out"
grep -q 'not encrypted' <<<"$out" || fail "no warning for a plain local backup"
(extract_backup "$work/falak-backup-20261004T060000Z.tar.gz" "$work/x5" falak-backup-20261004T060000Z >/dev/null 2>&1) && fail "a plain backup was accepted as a bucket restore"
pass "unauthenticated formats restore only from local files, with a warning"

# --- what a restored .env may not change ---------------------------------------------------------------------
cp "$FALAK_DIR/.env" "$work/env.saved"
printf 'APP_KEY=base64:restored\nFALAK_IMAGE_PREFIX=evil.example/x\nFALAK_VERSION=v9.9.9\nFALAK_REPO=evil/falak\nCOMPOSE_FILE=/tmp/evil.yml\nexport DOCKER_HOST=tcp://evil:2375\nCOMPOSE_PROFILES=evil\n' > "$work/restored.env"
printf 'COMPOSE_PROFILES=observability\n' >> "$FALAK_DIR/.env"
restore_env "$work/restored.env"
grep -q '^APP_KEY=base64:restored$' "$FALAK_DIR/.env" || fail "the backup's APP_KEY was not restored"
grep -q '^FALAK_IMAGE_PREFIX=ghcr.io/x$' "$FALAK_DIR/.env" || fail "the image prefix came from the backup: $(grep IMAGE "$FALAK_DIR/.env")"
grep -q '^FALAK_VERSION=v0.10.0$' "$FALAK_DIR/.env" || fail "the version came from the backup"
grep -q 'FALAK_REPO' "$FALAK_DIR/.env" && fail "a repo this host never set came from the backup"
grep -qE 'COMPOSE_FILE|DOCKER_HOST' "$FALAK_DIR/.env" && fail "compose / docker settings came from the backup"
grep -q '^COMPOSE_PROFILES=observability$' "$FALAK_DIR/.env" || fail "this host's profiles were lost"
cp "$work/env.saved" "$FALAK_DIR/.env"
pass "a restore keeps this host's version, image source and compose settings and drops COMPOSE_* / DOCKER_* from the backup"

# --- KMS / Vault backups on a fresh (local) host, newer backups, the lock, the env fallback ---------------------
kb="$work/kmsbackup"; mkdir -p "$kb"; printf 'kek_provider=aws-kms\nkek_ids=ffffffffffffffff\n' > "$kb/manifest"
restore_kek_check "$kb" 0 || fail "a KMS backup was refused for lacking a local KEK"
printf 'kek_provider=local\nkek_ids=ffffffffffffffff\n' > "$kb/manifest"
(restore_kek_check "$kb" 0 >/dev/null 2>&1) && fail "a local-KEK backup without its KEK was accepted"
pass "restore checks KEKs by the backup's provider, not the fresh host's"

version_newer v0.11.0 v0.10.0 || fail "v0.11.0 > v0.10.0"
version_newer v0.10.10 v0.10.9 || fail "v0.10.10 > v0.10.9"
version_newer v0.10.0 v0.10.0 && fail "equal versions"
version_newer v0.9.0 v0.10.0 && fail "older is newer"
version_newer main v0.10.0 && fail "main compared"
grep -q 'newer than this host' "$here/../falak-ctl" || fail "no refusal of newer backups"
pass "backups made by a newer Falak are told apart (restore refuses them without --force)"

flock() { return 1; }
out="$( (ctl_lock) 2>&1)" && fail "ctl_lock went ahead while locked"
grep -q 'is running; try again' <<<"$out" || fail "lock message: $out"
out="$( (ctl_lock 5) 2>&1)" && fail "a waiting ctl_lock went ahead"
grep -q 'still runs after 5s' <<<"$out" || fail "wait message: $out"
# shellcheck disable=SC2034  # read by ctl_lock
(CTL_LOCKED=1; ctl_lock) || fail "a nested command could not take the lock it holds"
unset -f flock
grep -q 'ctl_lock 3600; cmd_backup_scheduled' "$here/../falak-ctl" || fail "scheduled backups don't wait for the lock"
grep -q 'restore) ctl_lock; cmd_restore' "$here/../falak-ctl" || fail "restore is not locked"
grep -q 'ctl_lock; cmd_update' "$here/../falak-ctl" || fail "update is not locked"
pass "backup, restore, update and drill take .ctl.lock: timers wait, commands by hand fail fast"

(FALAK_BACKUP_S3_ENDPOINT=https://env.example; DR_DIR="$work/nodr"; DR_FILE="$work/nodr/dr.env"; export FALAK_BACKUP_S3_ENDPOINT
  [ -z "$(dr_get FALAK_BACKUP_S3_ENDPOINT)" ] || fail "dr_get read the environment outside a restore"
  # shellcheck disable=SC2034  # read by dr_get
  DR_ENV_FALLBACK=1; [ "$(dr_get FALAK_BACKUP_S3_ENDPOINT)" = https://env.example ] || fail "restore could not read the environment")
pass "only restore and dr setup read DR settings from the environment"

(dr_set FALAK_BACKUP_S3_ENDPOINT 'https://user:pw@s3.example.com/path'; dr_write_json
  grep -q '"endpoint":"https://s3.example.com"' "$DR_JSON" || fail "endpoint: $(cat "$DR_JSON")"
  if grep -q 'user:pw' "$DR_JSON"; then fail "userinfo in dr.json"; fi)
dr_set FALAK_BACKUP_S3_ENDPOINT https://s3.example.com; dr_write_json
pass "dr.json strips credentials from the endpoint"

# --- the drill runs in its own project and is torn down ------------------------------------------------------
compose_log="$work/compose.log"; : > "$compose_log"
docker_log="$work/docker.log"; : > "$docker_log"
compose() {
  printf '%s|%s|%s|%s\n' "$FALAK_PROJECT" "$ENV_FILE" "$COMPOSE_FILE" "$*" >> "$compose_log"
  case "$*" in
    *"psql -U falak -d falak -tA"*) printf 'users:2\n' ;;
    *"migrate:status"*) printf '  2026_01_01_000000_x ........ [1] Ran\n' ;;
    *"falak:keys:check --json"*) printf '{"ok":true,"provider":"local","kek_id":"a","data_keys":3,"stale":0,"failed":0,"kek_ids":["a"]}\n' ;;
  esac
  case "$*" in *pg_restore*|*"psql -v ON_ERROR_STOP"*) cat > /dev/null ;; esac
  return 0
}
docker() { printf '%s\n' "$*" >> "$docker_log"; case "$*" in run*) cat > /dev/null ;; esac; return 0; }
kek_secure() { chmod 0400 "$1"; }
ensure_kek --quiet >/dev/null 2>&1 || true
before="$(cat "$FALAK_DIR/.env")"
out="$(dr_drill --backup "$got" 2>&1)" || fail "drill failed: $out"
grep -v '^falak-drill|' "$compose_log" && fail "a drill compose call ran outside falak-drill"
grep -q "^falak-drill|$FALAK_DIR/drill/.env|$FALAK_DIR/drill/deploy/compose.yml|up -d --wait --wait-timeout 600 control-plane" "$compose_log" \
  || fail "the drill's control plane was not started in its own directory: $(cat "$compose_log")"
grep -q '^falak-drill|.*|down -v --remove-orphans$' "$compose_log" || fail "the drill was not torn down"
grep -qE 'up .*(edge|horizon|scheduler|agent-api|builder)' "$compose_log" && fail "the drill started a service that talks to the outside"
grep -q 'falak_' "$docker_log" && fail "the drill touched a volume of the install: $(grep falak_ "$docker_log")"
grep -q 'falak-drill_falak-ca' "$docker_log" || fail "the drill did not restore into its own volumes"
[ ! -e "$FALAK_DIR/drill" ] || fail "the drill directory was left behind"
[ "$(cat "$FALAK_DIR/.env")" = "$before" ] || fail "the drill changed the install's .env"
grep -q '"last_drill":{"at":"[^"]*","ok":true' "$DR_JSON" || fail "dr.json drill: $(cat "$DR_JSON")"
grep -q 'ps -aq --filter label=com.docker.compose.project=falak-drill' "$docker_log" || fail "leftovers of earlier drills are not looked for"
pass "dr drill restores into falak-drill (own dir, volumes, no edge or workers), checks it, tears it down, reports ok"

# The drill's settings: other ports and subnet, this host's version, no pulls.
(
  w="$work/drillwork"; mkdir -p "$w"; extract_backup "$got" "$w"
  drill_prepare "$w" "$work/dd"
  grep -q '^FALAK_HTTP_PORT=18080$' "$work/dd/.env" || fail "drill http port"
  grep -q '^FALAK_HTTPS_PORT=18443$' "$work/dd/.env" || fail "drill https port"
  grep -q '^FALAK_EDGE_SUBNET=10.213.78.0/24$' "$work/dd/.env" || fail "drill subnet: $(grep SUBNET "$work/dd/.env")"
  grep -q '^FALAK_PULL=0$' "$work/dd/.env" || fail "drill pulls"
  grep -q '^FALAK_VERSION=v0.10.0$' "$work/dd/.env" || fail "drill version"
  [ -f "$work/dd/secrets/kek" ] || fail "drill has no KEK"
  grep -q 'restart: unless-stopped' "$work/dd/deploy/compose.yml" && fail "drill containers restart by themselves"
  grep -q 'restart: "no"' "$work/dd/deploy/compose.yml" || fail "no restart policy in the drill"
  env_set FALAK_EDGE_SUBNET 10.213.78.0/24
  [ "$(drill_subnet)" = 10.213.79.0/24 ] || fail "drill subnet collides with the install's"
)
(FALAK_PROJECT=falak; drill_switch "$work/dd"; [ "$FALAK_PROJECT" = falak-drill ] || fail "drill project $FALAK_PROJECT")
docker() { printf '%s\n' "$*" >> "$docker_log"; case "$*" in "ps -aq"*) printf 'c1\nc2\n' ;; esac; return 0; }
drill_teardown
grep -q '^rm -f c1 c2$' "$docker_log" || fail "leftover drill containers were not removed: $(tail -3 "$docker_log")"
docker() { printf '%s\n' "$*" >> "$docker_log"; case "$*" in run*) cat > /dev/null ;; esac; return 0; }
pass "the drill uses ports 18080/18443, another edge subnet, this host's version, no pulls, no restarts; leftovers are removed"

compose() { case "$*" in *falak:keys:check*) printf '{"ok":false,"stale":0,"failed":2}\n'; return 1 ;; *"psql -U falak -d falak -tA"*) printf 'users:1\n' ;; *pg_restore*) cat >/dev/null ;; esac; return 0; }
(dr_drill --backup "$got" >/dev/null 2>&1) && fail "a drill with failing checks passed"
grep -q '"ok":false' "$DR_JSON" || fail "failed drill not reported"
grep -q 'encryption keys' "$DR_STATE" || fail "failed check not named: $(grep drill_message "$DR_STATE")"
grep -q 'row counts: differ: users 1/2' "$DR_STATE" || fail "row count mismatch not named: $(grep drill_message "$DR_STATE")"
[ ! -e "$FALAK_DIR/drill" ] || fail "a failed drill left its directory"
pass "a failed drill names the failing checks in dr.json and is torn down too"

# --- a restore says how old the backup is, and warns past twice the schedule (newer objects deleted?) -----------
ag="$work/age"; mkdir -p "$ag"
printf 'created_at=%s\n' "$(date -u +%Y%m%dT%H%M%SZ)" > "$ag/manifest"
out="$(restore_age_note "$ag" 2>&1)"
grep -q 'backup taken' <<<"$out" || fail "no age shown: $out"
if grep -q 'older than twice' <<<"$out"; then fail "a fresh backup was called old"; fi
printf 'created_at=20200101T000000Z\n' > "$ag/manifest"
out="$(restore_age_note "$ag" 2>&1)"
grep -q 'older than twice the 4 h schedule' <<<"$out" || fail "an old backup was not flagged: $out"
pass "a restore prints the backup's time and age and warns when it is older than twice the schedule"

# --- install.sh --restore-from ----------------------------------------------------------------------------
# shellcheck disable=SC2030,SC2031  # each run sources install.sh in its own subshell
inst() { ( export FALAK_INSTALL_SOURCED=1 FALAK_DOMAIN=falak.example.com FALAK_EMAIL=ops@example.com FALAK_DIR="$work/inst"
  # shellcheck source=/dev/null
  . "$here/../install.sh" "$@"; printf '%s\n' "$RESTORE_FROM" ) }
[ "$(inst --restore-from s3://latest 2>/dev/null)" = s3://latest ] || fail "--restore-from s3://latest"
[ "$(inst --restore-from=s3://falak-backup-20261004T060000Z 2>/dev/null)" = s3://falak-backup-20261004T060000Z ] || fail "--restore-from=NAME"
[ "$(FALAK_RESTORE_FROM=s3://latest inst 2>/dev/null)" = s3://latest ] || fail "FALAK_RESTORE_FROM"
[ -z "$(inst 2>/dev/null)" ] || fail "restore without the flag"
for bad in /root/backup.tar.gz s3://other/falak-backup-x s3://falak-backup-../x s3:// s3://foo; do
  (inst --restore-from "$bad" >/dev/null 2>&1) && fail "--restore-from $bad was accepted"
done
grep -q 'restore_control_plane' "$here/../install.sh" || fail "install.sh does not restore"
# The schedule is installed only after a successful restore (a timer must never upload an empty install first).
fn="$(awk '/^restore_control_plane\(\)/,/^}/' "$here/../install.sh")"
[ "$(grep -n 'kctl restore' <<<"$fn" | cut -d: -f1)" -lt "$(grep -n 'kctl dr setup' <<<"$fn" | cut -d: -f1)" ] || fail "dr setup runs before the restore"
grep -q -- '--passphrase-file' <<<"$fn" || fail "install.sh hands the passphrase over on the command line"
# shellcheck disable=SC2016  # a literal $1
grep -q 'export "\$1' "$here/../install.sh" && fail "install.sh exports the DR secrets"
grep -q "trap 'rm -rf \"\$RESTORE_TMP\"' EXIT" <<<"$fn" || fail "the secret files are not removed on exit"
grep -qE '(^|[^_])env FALAK_BACKUP' <<<"$fn" && fail "secrets on env's command line"
# shellcheck disable=SC2030,SC2031
( export FALAK_INSTALL_SOURCED=1 FALAK_DOMAIN=falak.example.com FALAK_EMAIL=ops@example.com FALAK_DIR="$work/inst2"
  mkdir -p "$FALAK_DIR"; : > "$FALAK_DIR/.env"
  # shellcheck source=/dev/null
  . "$here/../install.sh"
  dc() { :; }
  record_operator_organization '{"user_id":"u","organization_id":"01jb2c3d4e5f6g7h8j9k0m1n2p","organization":"Acme"}'
  grep -q '^FALAK_DR_ORGANIZATION=01jb2c3d4e5f6g7h8j9k0m1n2p$' "$FALAK_DIR/.env" || fail "operator organization not recorded"
  record_operator_organization '{"organization_id":"01zzzzzzzzzzzzzzzzzzzzzzzz"}'
  grep -q '^FALAK_DR_ORGANIZATION=01jb2c3d4e5f6g7h8j9k0m1n2p$' "$FALAK_DIR/.env" || fail "a re-run moved the operator organization" ) || exit 1
pass "install.sh parses --restore-from, schedules backups only after the restore, never exports secrets, records the operator organization"
