#!/usr/bin/env bash
# Assemble GitHub release assets (used by .github/workflows/release.yml; runnable locally).
#   deploy/tools/release-assets.sh <tag> <owner/repo> <out-dir>
# Expects agent/bin/ from `make -C agent build VERSION=<tag>`. Produces:
#   falak-agent-linux-{amd64,arm64}, falak-builder-linux-{amd64,arm64}, falak-{linux,darwin}-{amd64,arm64},
#   falak-restore-{linux,darwin}-{amd64,arm64} (decrypts backups offline),
#   falak-deploy.tar.gz (deploy/ + observability/, install.sh/falak-ctl pinned to <owner/repo>),
#   install.sh (defaults to <tag>), install-cli.sh (the `falak` CLI installer, defaults to <tag>), falak-ctl, SHA256SUMS
# install.sh, install-cli.sh and falak-ctl get the release signing key (agent/internal/cli/release-signing.pub) pinned
# in. FALAK_IMAGE_DIGESTS=<file> ("falak-<image> sha256:<digest>" lines) goes into the bundle as deploy/image-digests.
# release.yml signs SHA256SUMS (SHA256SUMS.sig) afterwards.
set -euo pipefail
tag="${1:?tag}"; repo="${2:?owner/repo}"; out="${3:?out dir}"
root="$(cd "$(dirname "$0")/../.." && pwd)"
bin="$root/agent/bin"
[ -x "$bin/falak-agent-linux-amd64" ] || { echo "missing $bin (run: make -C agent build VERSION=$tag)" >&2; exit 1; }

rm -rf "$out"; mkdir -p "$out"
out="$(cd "$out" && pwd)"
for f in falak-agent-linux-amd64 falak-agent-linux-arm64 falak-builder-linux-amd64 falak-builder-linux-arm64 \
         falak-linux-amd64 falak-linux-arm64 falak-darwin-amd64 falak-darwin-arm64 \
         falak-restore-linux-amd64 falak-restore-linux-arm64 falak-restore-darwin-amd64 falak-restore-darwin-arm64; do
  cp "$bin/$f" "$out/$f"
done

stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
cp -R "$root/deploy" "$stage/deploy"
mkdir -p "$stage/observability"
for d in gateway grafana loki mimir tempo; do cp -R "$root/observability/$d" "$stage/observability/$d"; done
cp "$root/observability/compose.yml" "$root/observability/README.md" "$stage/observability/"
find "$stage" -name .DS_Store -delete
if [ -n "${FALAK_IMAGE_DIGESTS:-}" ]; then cp "$FALAK_IMAGE_DIGESTS" "$stage/deploy/image-digests"; fi
# The key's base64 body, one line (empty while release-signing.pub is the placeholder).
pubkey="$(awk '/^-----END PUBLIC KEY-----$/ { k = 0 } k; /^-----BEGIN PUBLIC KEY-----$/ { k = 1 }' "$root/agent/internal/cli/release-signing.pub" | tr -d '\n')"

pin() { # pin FILE : default repo -> $repo; install.sh defaults to this release
  local tmp="$1.tmp"
  sed -e "s#^DEFAULT_REPO=\".*\"#DEFAULT_REPO=\"$repo\"#" \
      -e "s#^FALAK_REPO_DEFAULT=\".*\"#FALAK_REPO_DEFAULT=\"$repo\"#" \
      -e "s#^VERSION=\"\${FALAK_VERSION:-}\"#VERSION=\"\${FALAK_VERSION:-$tag}\"#" \
      -e "s#^RELEASE_PUBKEY=\"\"#RELEASE_PUBKEY=\"$pubkey\"#" "$1" > "$tmp"
  chmod --reference="$1" "$tmp" 2>/dev/null || chmod 755 "$tmp"
  mv "$tmp" "$1"
}
pin "$stage/deploy/falak-ctl"
pin "$stage/deploy/install.sh"
pin "$stage/deploy/install-cli.sh"
cp "$stage/deploy/install.sh" "$out/install.sh"
cp "$stage/deploy/falak-ctl" "$out/falak-ctl"
cp "$stage/deploy/install-cli.sh" "$out/install-cli.sh"

if tar --version 2>/dev/null | grep -q GNU; then
  tar --sort=name --owner=0 --group=0 --numeric-owner --mtime="@${SOURCE_DATE_EPOCH:-0}" \
    -C "$stage" -czf "$out/falak-deploy.tar.gz" deploy observability
else
  tar -C "$stage" -czf "$out/falak-deploy.tar.gz" deploy observability
fi

cd "$out"
if command -v sha256sum >/dev/null 2>&1; then sha256sum -- * > SHA256SUMS; else shasum -a 256 -- * > SHA256SUMS; fi
cd - >/dev/null
echo "release assets for $tag in $out:"
(cd "$out" && ls -l)
