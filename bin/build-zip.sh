#!/usr/bin/env bash
#
# Build the distributable zip.
#
#   ./bin/build-zip.sh [output-dir]
#
# Produces leanroles-<version>.zip containing a single `leanroles/` directory,
# which is what WordPress expects from a plugin archive.
#
# What goes in is decided by .distignore, so there is one list rather than two
# that drift apart. Everything the plugin needs at runtime is in src/, assets/
# and libraries/, and none of it needs building — but the bundled library brings
# its own tests and composer.json along in the subtree, and those have no
# business in a user's wp-content.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-${ROOT}/dist}"
SLUG="leanroles"

VERSION="$(grep -m1 '^ \* Version:' "${ROOT}/${SLUG}.php" | tr -d ' ' | cut -d: -f2)"

if [ -z "${VERSION}" ]; then
	echo "Could not read the version from ${SLUG}.php" >&2
	exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE}"' EXIT

mkdir -p "${STAGE}/${SLUG}" "${OUT}"

# .distignore entries are paths relative to the plugin root, one per line, with
# # for comments — the same format wp dist-archive reads.
EXCLUDES=()
while IFS= read -r line; do
	case "${line}" in
		''|'#'*) continue ;;
	esac
	EXCLUDES+=( "--exclude=${line}" )
done < "${ROOT}/.distignore"

# The library travels as a subtree, so its development scaffolding arrives with
# it. Named here rather than in .distignore because .distignore also governs
# what the plugin's own repository publishes.
EXCLUDES+=(
	"--exclude=libraries/*/tests"
	"--exclude=libraries/*/composer.json"
	"--exclude=libraries/*/composer.lock"
	"--exclude=libraries/*/phpunit.xml.dist"
	"--exclude=libraries/*/phpcs.xml.dist"
	"--exclude=libraries/*/.github"
	"--exclude=libraries/*/.gitignore"
	"--exclude=libraries/*/.gitattributes"
)

rsync -a "${EXCLUDES[@]}" --exclude='.git' --exclude='dist' \
	"${ROOT}/" "${STAGE}/${SLUG}/"

ARCHIVE="${OUT}/${SLUG}-${VERSION}.zip"
rm -f "${ARCHIVE}"

( cd "${STAGE}" && zip -qr "${ARCHIVE}" "${SLUG}" -x '*.DS_Store' )

echo "${ARCHIVE}"
echo
unzip -l "${ARCHIVE}" | tail -1
