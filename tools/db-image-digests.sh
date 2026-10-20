#!/usr/bin/env bash
# Pins the database images the control plane ships: for every engine/version of images/db/versions.json, the digest of
# the multi-arch index ghcr.io/<owner>/falak-<engine>:<version><suffix> (published and signed by the db-images
# workflow), written sorted to control-plane/modules/Databases/config/db-image-digests.json. The agent runs nothing
# else (docs/DB_IMAGES.md, "Trust").
#
#   tools/db-image-digests.sh <tag-suffix> [--wait SECONDS] [--owner OWNER] [--out FILE]
#
#   <tag-suffix>  what follows <version> in the tag: -vX.Y.Z for a release tag, -rc for release/** branches, "" for main
#   --wait        keep retrying missing tags this long (the db-images run of the same tag may still be publishing)
#
# Fails (exit 1, nothing written) when any digest can't be resolved. Needs docker buildx and jq; `docker login ghcr.io`
# first when the packages are private.
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

[ $# -ge 1 ] || { echo "usage: $0 <tag-suffix> [--wait SECONDS] [--owner OWNER] [--out FILE]" >&2; exit 2; }
suffix=$1
shift
wait=0
owner=falak-sh
out=control-plane/modules/Databases/config/db-image-digests.json
while [ $# -gt 0 ]; do
	case "$1" in
	--wait) wait=$2; shift 2 ;;
	--owner) owner=$(printf '%s' "$2" | tr '[:upper:]' '[:lower:]'); shift 2 ;;
	--out) out=$2; shift 2 ;;
	*) echo "unknown option $1" >&2; exit 2 ;;
	esac
done
case "$suffix" in
'' | -rc | -v[0-9]*) ;;
*) echo "unexpected tag suffix '$suffix' (expected '', -rc or -vX.Y.Z)" >&2; exit 2 ;;
esac

# Digest of a tag's index, or nothing.
resolve() {
	docker buildx imagetools inspect "$1" --format '{{json .Manifest}}' 2>/dev/null | jq -r '.digest // empty' 2>/dev/null || true
}

deadline=$((SECONDS + wait))
doc='{}'
missing=()
while IFS=$'\t' read -r engine version; do
	ref="ghcr.io/$owner/falak-$engine:$version$suffix"
	while :; do
		digest=$(resolve "$ref")
		if [[ "$digest" =~ ^sha256:[0-9a-f]{64}$ ]]; then
			break
		fi
		if [ "$SECONDS" -ge "$deadline" ]; then
			digest=''
			break
		fi
		echo "waiting for $ref" >&2
		sleep 30
	done
	if [ -z "$digest" ]; then
		missing+=("$ref")
		continue
	fi
	# The control plane names PostgreSQL "postgresql"; the images "postgres".
	key=$engine
	[ "$engine" = postgres ] && key=postgresql
	doc=$(jq --arg e "$key" --arg v "$version" --arg d "$digest" '.[$e][$v] = $d' <<<"$doc")
	echo "$ref -> $digest" >&2
done < <(jq -r '.images[] | [.engine, .version] | @tsv' images/db/versions.json)

if [ ${#missing[@]} -gt 0 ]; then
	printf 'db-image-digests: no published image for %s\n' "${missing[@]}" >&2
	exit 1
fi
jq -S . <<<"$doc" >"$out.tmp"
mv "$out.tmp" "$out"
echo "wrote $out" >&2
