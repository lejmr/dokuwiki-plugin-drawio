#!/bin/sh
# Run the plugin tests in docker (no PHP needed on the host).
#
#   bin/test.sh                 # against DokuWiki stable
#   bin/test.sh master          # against the development branch
#   bin/test.sh oldstable
#   bin/test.sh stable golden   # just the fast _test/golden tier
set -eu

PLUGIN_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
# The checkout is owned by the host user, git inside the container is root:
# allow it without writing to anyone's global git config.
exec docker run --rm -v "$PLUGIN_DIR:/plugin" -w /plugin \
    -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0='*' \
    composer:2 sh bin/run-tests.sh "${1:-stable}" "${2:-}"
