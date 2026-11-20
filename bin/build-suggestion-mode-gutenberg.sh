#!/usr/bin/env bash
#
# Builds the Gutenberg commit pinned in tests/e2e/suggestion-mode/gutenberg.json
# into .cache/suggestion-mode/gutenberg, the plugin .wp-env.suggestion-mode.json
# installs. Does nothing when that build is already there.
#
# Set GUTENBERG_KEEP_SOURCE=1 to keep the Gutenberg checkout after the build.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PIN="$ROOT/tests/e2e/suggestion-mode/gutenberg.json"
REPOSITORY="$(node -p "require('$PIN').repository")"
SHA="$(node -p "require('$PIN').sha")"
CACHE="$ROOT/.cache/suggestion-mode"
PLUGIN="$CACHE/gutenberg"
SOURCE="$CACHE/source"

if [ -f "$PLUGIN/.built-sha" ] && [ "$(cat "$PLUGIN/.built-sha")" = "$SHA" ]; then
	echo "Gutenberg $SHA is already built in $PLUGIN."
	exit 0
fi

echo "Building Gutenberg $REPOSITORY@$SHA. This takes a while."
rm -rf "$SOURCE"
mkdir -p "$SOURCE"
git -C "$SOURCE" init -q
git -C "$SOURCE" fetch -q --depth=1 "https://github.com/$REPOSITORY.git" "$SHA"
git -C "$SOURCE" checkout -q FETCH_HEAD

cd "$SOURCE"
# Gutenberg pins its own Node version; use it when nvm is around.
if [ -z "${CI:-}" ] && [ -s "${NVM_DIR:-$HOME/.nvm}/nvm.sh" ]; then
	set +u
	# shellcheck disable=SC1091
	. "${NVM_DIR:-$HOME/.nvm}/nvm.sh"
	nvm install
	set -u
fi
# NO_CHECKS skips the dirty tree check and the prompt to clean the checkout.
NO_CHECKS=true npm run build:plugin-zip

rm -rf "$PLUGIN"
mkdir -p "$PLUGIN"
unzip -q "$SOURCE/gutenberg.zip" -d "$PLUGIN"
# The archive keeps its files at the root; catch a layout change before the
# build is stamped and cached.
if [ ! -f "$PLUGIN/gutenberg.php" ]; then
	echo "The Gutenberg archive has no gutenberg.php at its root." >&2
	exit 1
fi
echo "$SHA" > "$PLUGIN/.built-sha"

if [ -z "${GUTENBERG_KEEP_SOURCE:-}" ]; then
	rm -rf "$SOURCE"
fi

echo "Built Gutenberg $SHA into $PLUGIN."
