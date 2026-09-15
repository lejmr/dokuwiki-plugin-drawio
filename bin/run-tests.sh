#!/bin/sh
# Run the plugin's PHPUnit tests against a DokuWiki checkout.
# Needs php + composer + git on PATH. Use bin/test.sh to run it in docker.
#
#   bin/run-tests.sh [master|stable|oldstable]   (default: stable)
set -eu

BRANCH="${1:-stable}"
# Friendly names (same spelling as the docker image tags) -> git branches.
case "$BRANCH" in
    oldstable) REF=old-stable ;;
    dev|devel|development) REF=master ;;
    *) REF="$BRANCH" ;;
esac
PLUGIN_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
CACHE="${DW_CACHE:-$PLUGIN_DIR/.cache}/dokuwiki-$BRANCH"

if [ ! -d "$CACHE/.git" ]; then
    echo "==> cloning DokuWiki ($BRANCH)"
    git clone --depth 1 --branch "$REF" https://github.com/splitbrain/dokuwiki.git "$CACHE"
else
    echo "==> updating DokuWiki ($BRANCH)"
    git -C "$CACHE" fetch --depth 1 origin "$REF"
    git -C "$CACHE" reset --hard "origin/$REF"
fi

echo "==> DokuWiki $BRANCH ($REF) is $(git -C "$CACHE" describe --tags --always 2>/dev/null || echo unknown)"

composer install --no-interaction --no-progress --working-dir="$CACHE"
composer install --no-interaction --no-progress --working-dir="$CACHE/_test"

# Copy (not symlink) the plugin in: a symlink back into the repo would nest the
# DokuWiki checkout inside itself. The glob skips .git and .cache.
DEST="$CACHE/lib/plugins/drawio"
rm -rf "$DEST"
mkdir -p "$DEST"
for entry in "$PLUGIN_DIR"/*; do
    cp -R "$entry" "$DEST/"
done

echo "==> running tests"
cd "$CACHE/_test"
exec php vendor/bin/phpunit --stderr --colors=never --test-suffix .test.php,Test.php ../lib/plugins/drawio/_test
