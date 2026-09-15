#!/bin/bash
set -e

# Seed a ready-to-use wiki on first start so `docker compose up` lands in a
# working wiki instead of the install wizard. Existing files are never touched.
mkdir -p /storage/conf /storage/data/pages
for f in /seed/conf/*; do
  [ -e "/storage/conf/$(basename "$f")" ] || cp "$f" /storage/conf/
done
for f in /seed/pages/*; do
  [ -e "/storage/data/pages/$(basename "$f")" ] || cp "$f" /storage/data/pages/
done

exec /dokuwiki-entrypoint.sh "$@"
