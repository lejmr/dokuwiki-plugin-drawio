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

# Same pattern, for media: whatsnew.txt's section 2 walks the tester through
# an old-style diagram (XML embedded in the PNG, no .drawio source) - the
# only way to show that case is to seed one, since the plugin itself only
# ever creates the new two-file kind. Namespaces are preserved (media/wiki/...
# -> data/media/wiki/...); a file the tester has since edited is never
# overwritten, same guarantee as conf/pages above.
if [ -d /seed/media ]; then
  find /seed/media -type f | while read -r f; do
    rel="${f#/seed/media/}"
    dest="/storage/data/media/$rel"
    mkdir -p "$(dirname "$dest")"
    [ -e "$dest" ] || cp "$f" "$dest"
  done
fi

# The admin user is generated here rather than shipped as a committed
# users.auth.php. That file has no `<?php` opening tag - DokuWiki's plain-text
# auth format is a colon-separated line, not a PHP script - so if it is ever
# served over the web (a plugin install has no .htaccess protecting
# lib/plugins/) the password hash comes back as plain text. Generating it at
# container start means there is nothing to serve, in the repo or on disk
# before this line runs. Password defaults to "admin", override with
# DW_ADMIN_PASSWORD.
if [ ! -e /storage/conf/users.auth.php ]; then
  DW_ADMIN_PASSWORD="${DW_ADMIN_PASSWORD:-admin}" php -r '
    $hash = password_hash(getenv("DW_ADMIN_PASSWORD"), PASSWORD_BCRYPT);
    file_put_contents(
        "/storage/conf/users.auth.php",
        "# users.auth.php\n" .
        "# Generated at container start by docker/dev-entrypoint.sh - do not edit, not committed.\n" .
        "admin:$hash:admin:admin@example.com:admin,user\n"
    );
  '
fi

exec /dokuwiki-entrypoint.sh "$@"
