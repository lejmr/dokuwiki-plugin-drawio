#!/bin/sh
# Set plugin.info.txt date to today, so the DokuWiki updater offers the release.
set -eu
cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
today=$(date +%F)
sed -i.bak -E "s/^(date[[:space:]]+).*/\1$today/" plugin.info.txt && rm -f plugin.info.txt.bak
echo "plugin.info.txt date -> $today"
