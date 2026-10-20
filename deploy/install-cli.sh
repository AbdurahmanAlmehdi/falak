#!/bin/sh
# install-cli.sh — install the `falak` CLI on macOS or Linux (amd64/arm64).
#
#   curl -fsSL https://github.com/falak-sh/falak/releases/latest/download/install-cli.sh | sh
#
# Then log in (the token comes from <panel>/settings/api-tokens):
#   falak login --url https://falak.example.com
#
# Environment:
#   FALAK_VERSION      tag to install (default: the release this script came from, else the latest)
#   FALAK_INSTALL_DIR  where to put the binary (default /usr/local/bin if writable, else ~/.local/bin)
#   FALAK_URL          if set, run `falak login --url $FALAK_URL` after installing (asks for the token)
#   FALAK_REPO         GitHub owner/repo to download from
# This is the client CLI. falak-ctl (the server admin tool) is installed on the control-plane host by install.sh.
set -eu

DEFAULT_REPO="falak-sh/falak"
VERSION="${FALAK_VERSION:-}"
REPO="${FALAK_REPO:-$DEFAULT_REPO}"

if [ -t 1 ]; then B=$(printf '\033[1m'); R=$(printf '\033[31m'); G=$(printf '\033[32m'); N=$(printf '\033[0m'); else B=; R=; G=; N=; fi
info() { printf '%s==>%s %s\n' "$B" "$N" "$*"; }
ok()   { printf '  %s✓%s %s\n' "$G" "$N" "$*"; }
die()  { printf '%serror:%s %s\n' "$R" "$N" "$*" >&2; exit 1; }

command -v curl >/dev/null 2>&1 || die "curl is required"

case "$(uname -s)" in
  Darwin) os=darwin ;;
  Linux) os=linux ;;
  *) die "unsupported OS $(uname -s) (macOS and Linux only)" ;;
esac
case "$(uname -m)" in
  x86_64 | amd64) arch=amd64 ;;
  arm64 | aarch64) arch=arm64 ;;
  *) die "unsupported CPU $(uname -m) (amd64 and arm64 only)" ;;
esac
asset="falak-$os-$arch"

if [ -n "$VERSION" ]; then
  base="https://github.com/$REPO/releases/download/$VERSION"
else
  base="https://github.com/$REPO/releases/latest/download"
fi

if [ -z "${FALAK_INSTALL_DIR:-}" ]; then
  if [ -w /usr/local/bin ]; then FALAK_INSTALL_DIR=/usr/local/bin; else FALAK_INSTALL_DIR="$HOME/.local/bin"; fi
fi

tmp="$(mktemp -d "${TMPDIR:-/tmp}/falak-cli.XXXXXX")"
trap 'rm -rf "$tmp"' EXIT INT TERM

info "Downloading $asset (${VERSION:-latest}) from $REPO"
curl -fsSL --retry 3 -o "$tmp/$asset" "$base/$asset" || die "download failed: $base/$asset"
curl -fsSL --retry 3 -o "$tmp/SHA256SUMS" "$base/SHA256SUMS" || die "download failed: $base/SHA256SUMS"

want="$(awk -v f="$asset" '$2 == f || $2 == "*"f { print $1 }' "$tmp/SHA256SUMS")"
[ -n "$want" ] || die "$asset is not listed in SHA256SUMS"
if command -v sha256sum >/dev/null 2>&1; then
  got="$(sha256sum "$tmp/$asset" | awk '{ print $1 }')"
else
  got="$(shasum -a 256 "$tmp/$asset" | awk '{ print $1 }')"
fi
[ "$got" = "$want" ] || die "checksum mismatch for $asset (expected $want, got $got)"
ok "checksum verified"

mkdir -p "$FALAK_INSTALL_DIR"
chmod 755 "$tmp/$asset"
mv "$tmp/$asset" "$FALAK_INSTALL_DIR/falak" || die "cannot write $FALAK_INSTALL_DIR (set FALAK_INSTALL_DIR, or re-run with sudo)"
# curl sets no quarantine flag, but clear it in case the file was fetched another way.
[ "$os" = darwin ] && xattr -d com.apple.quarantine "$FALAK_INSTALL_DIR/falak" 2>/dev/null || true
ok "installed $("$FALAK_INSTALL_DIR/falak" version) to $FALAK_INSTALL_DIR/falak"

# shellcheck disable=SC2016  # $PATH is meant literally in the printed line
case ":$PATH:" in
  *":$FALAK_INSTALL_DIR:"*) ;;
  *) printf '\n  %s is not on your PATH. Add this to your shell profile:\n    export PATH="%s:$PATH"\n' "$FALAK_INSTALL_DIR" "$FALAK_INSTALL_DIR" ;;
esac

if [ -n "${FALAK_URL:-}" ]; then
  if [ -r /dev/tty ]; then
    info "Logging in to $FALAK_URL (approve it in the browser)"
    "$FALAK_INSTALL_DIR/falak" login --url "$FALAK_URL" </dev/tty || die "login failed — retry: falak login --url $FALAK_URL"
  else
    printf '\n  No terminal for the token prompt. Log in with: falak login --url %s\n' "$FALAK_URL"
  fi
else
  printf '\n  Next: falak login --url https://<your-panel>   (signs in through the browser)\n'
fi
