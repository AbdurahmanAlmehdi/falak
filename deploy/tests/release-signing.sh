#!/usr/bin/env bash
# Scripted test for release verification in falak-ctl and install.sh: once a signing key is pinned, SHA256SUMS needs
# a valid SHA256SUMS.sig (none, or one over other bytes, is fatal); without a key it is checked unsigned. The Falak
# images pulled must carry the digests the bundle recorded (deploy/image-digests). Needs openssl 3 (skipped without).
#   deploy/tests/release-signing.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$*"; }
if ! openssl pkeyutl -help 2>&1 | grep -q rawin; then echo "skip - no OpenSSL 3"; exit 0; fi
work="$(mktemp -d "${TMPDIR:-/tmp}/falak-release-signing.XXXXXX")"
trap 'rm -rf "$work"' EXIT

# One definition each: deploy/install.sh and deploy/falak-ctl carry the same helpers.
for fn in verify_sums_sig verify_image_digests; do
  body() { sed -n "/^$fn() {/,/^}/p" "$1"; }
  if ! { [ -n "$(body "$here/../install.sh")" ] && [ "$(body "$here/../install.sh")" = "$(body "$here/../falak-ctl")" ]; }; then fail "$fn differs between install.sh and falak-ctl"; fi
done
grep -q '^RELEASE_PUBKEY=""$' "$here/../install-cli.sh" || fail "install-cli.sh has no RELEASE_PUBKEY for release-assets.sh to pin"
pass "install.sh and falak-ctl share verify_sums_sig and verify_image_digests; install-cli.sh has RELEASE_PUBKEY"

export FALAK_DIR="$work/falak" FALAK_CTL_SOURCED=1
mkdir -p "$FALAK_DIR"
printf 'FALAK_IMAGE_PREFIX=ghcr.io/acme\nFALAK_VERSION=v0.9.0\n' > "$FALAK_DIR/.env"
# shellcheck source=/dev/null
. "$here/../falak-ctl"

openssl genpkey -algorithm ed25519 -out "$work/key.pem" 2>/dev/null
rel="$work/rel"; mkdir -p "$rel/v1" "$rel/v2" "$rel/v3"
for v in v1 v2 v3; do printf '%064d  falak-deploy.tar.gz\n' 0 > "$rel/$v/SHA256SUMS"; done
openssl pkeyutl -sign -rawin -inkey "$work/key.pem" -in "$rel/v1/SHA256SUMS" -out "$rel/v1/SHA256SUMS.sig"
echo other > "$work/other"
openssl pkeyutl -sign -rawin -inkey "$work/key.pem" -in "$work/other" -out "$rel/v2/SHA256SUMS.sig" # v3: unsigned
check() { # check VERSION : verify_sums_sig against the file:// release VERSION
  local d="$work/dl-$1"; rm -rf "$d"; mkdir -p "$d"; cp "$rel/$1/SHA256SUMS" "$d/"
  (verify_sums_sig "$d" "file://$rel/$1") 2>"$work/err"
}

RELEASE_PUBKEY=""
check v3 || fail "no key: an unsigned release was refused"
grep -q 'checked unsigned' "$work/err" || fail "no key: no warning ($(cat "$work/err"))"
# shellcheck disable=SC2034  # read by verify_sums_sig (sourced from falak-ctl)
RELEASE_PUBKEY="$(openssl pkey -in "$work/key.pem" -pubout | grep -v '^-----' | tr -d '\n')"
check v1 || fail "a valid signature was refused: $(cat "$work/err")"
if check v2; then fail "a signature over other bytes was accepted"; fi
grep -q 'does not match its signature' "$work/err" || fail "bad signature: $(cat "$work/err")"
if check v3; then fail "an unsigned release was accepted"; fi
grep -q 'refusing an unsigned release' "$work/err" || fail "unsigned: $(cat "$work/err")"
pass "with a key: a valid SHA256SUMS.sig passes, a wrong or missing one is fatal; without a key: unsigned with a warning"

good="sha256:$(printf 'a%.0s' {1..64})"; bad="sha256:$(printf 'b%.0s' {1..64})"
docker() { # docker image inspect --format F REF
  [ "$1 $2" = "image inspect" ] || fail "unexpected docker $*"
  case "$5" in
    ghcr.io/acme/falak-control-plane:v0.9.0 | ghcr.io/acme/falak-edge:v0.9.0) printf 'ghcr.io/acme/%s@%s\n' x "$good" ;;
    ghcr.io/acme/falak-builder:v0.9.0) printf 'ghcr.io/acme/x@%s\n' "$BUILDER_DIGEST" ;;
    *) return 1 ;;
  esac
}
mkdir -p "$FALAK_DIR/deploy"
printf 'falak-control-plane %s\nfalak-edge %s\nfalak-builder %s\nfalak-fn-go %s\n' "$good" "$good" "$good" "$bad" > "$FALAK_DIR/deploy/image-digests"
BUILDER_DIGEST="$good"
verify_image_digests "$FALAK_DIR/deploy/image-digests" ghcr.io/acme v0.9.0 2>/dev/null || fail "recorded digests were refused"
BUILDER_DIGEST="$bad"
if verify_image_digests "$FALAK_DIR/deploy/image-digests" ghcr.io/acme v0.9.0 2>/dev/null; then fail "a builder image with another digest was accepted"; fi
verify_image_digests "$work/none" ghcr.io/acme v0.9.0 || fail "no digests file (a source checkout) failed"
pass "pulled Falak images must match deploy/image-digests (other images ignored; no file, no check)"
