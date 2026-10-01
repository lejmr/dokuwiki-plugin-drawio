#!/bin/sh
# Render a page with {{drawio}} on an old DokuWiki release (issue #110).
# PHPUnit can't run there (old releases ship no _test/composer.json), so this
# just loads the page in the built-in PHP server and fails on a fatal.
#
#   bin/legacy-smoke.sh release-2020-07-29a 7.4 [plugin-dir]
set -eu
TAG=$1; PHP=$2
SRC=$(CDPATH= cd -- "${3:-$(dirname -- "$0")/..}" && pwd)
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)/.cache/smoke-$TAG-$$
rm -rf "$ROOT"; mkdir -p "$ROOT"
curl -fsSL --retry 5 --retry-delay 3 --retry-all-errors -o "$ROOT.tgz" "https://github.com/dokuwiki/dokuwiki/archive/refs/tags/$TAG.tar.gz"
tar xz --strip-components=1 -C "$ROOT" -f "$ROOT.tgz"; rm -f "$ROOT.tgz"
rm -f "$ROOT/install.php"
cd "$ROOT"
mkdir -p data/pages data/media data/cache data/index data/meta data/attic data/media_attic data/media_meta data/locks data/tmp lib/plugins/drawio
for e in "$SRC"/*; do cp -R "$e" lib/plugins/drawio/; done
cp "$SRC/_test/real-drawio-export.png" data/media/a.png
printf '{{drawio>a}} {{drawio>a?300x200}} {{drawio>ns:missing}}\n' > data/pages/t.txt
printf "<?php\n\$conf['useacl']=0;\n" > conf/local.php
NAME=smoke-$$
docker run -d --rm --name "$NAME" -p 0:80 -v "$ROOT:/var/www/html" -w /var/www/html "php:$PHP-cli" php -S 0.0.0.0:80 >/dev/null
trap 'docker rm -f "$NAME" >/dev/null 2>&1; rm -rf "$ROOT"' EXIT
PORT=$(docker port "$NAME" 80 | head -1 | sed 's/.*://')
sleep 3
OUT=$(curl -s "http://localhost:$PORT/doku.php?id=t")
echo "$OUT" | grep -q "class='mediacenter'" || { echo "FAIL: no diagram rendered"; echo "$OUT" | sed 's/<[^>]*>//g' | grep -i -E "error|fatal" | head -3; exit 1; }
if echo "$OUT" | grep -q -i -E "Fatal error|Error loading plugin|Uncaught"; then echo "FAIL: error text on page"; exit 1; fi
echo "OK: $TAG on PHP $PHP"
