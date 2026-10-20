#!/usr/bin/env bash
# Fill templates/ (git-ignored) with the catalog release pinned in templates.lock — the copy the control-plane image
# bakes in — for local development and the sim. The catalog itself lives in github.com/falak-sh/templates.
#
#   tools/fetch-templates.sh [target-dir]    (default: templates/ at the repository root)
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
target=${1:-$root/templates}
read -r version sha256 <"$root/templates.lock"

if [[ -f $target/VERSION && $(cat "$target/VERSION") == "$version" ]]; then
    exit 0
fi

archive=$(mktemp)
trap 'rm -f "$archive"' EXIT
curl -fsSL "https://github.com/falak-sh/templates/releases/download/v${version}/catalog.tar.gz" -o "$archive"
if command -v sha256sum >/dev/null; then sum=$(sha256sum "$archive"); else sum=$(shasum -a 256 "$archive"); fi
if [[ ${sum%% *} != "$sha256" ]]; then
    echo "catalog v${version}: sha256 mismatch (templates.lock says $sha256)" >&2
    exit 1
fi

rm -rf "$target"
mkdir -p "$target"
tar -xzf "$archive" -C "$target"
echo "templates/: catalog v${version}"
