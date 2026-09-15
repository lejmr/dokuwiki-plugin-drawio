ARG DW_VERSION=stable
FROM dokuwiki/dokuwiki:${DW_VERSION}

# The plugin source is bind-mounted at /src. It must NOT be mounted inside
# /storage: the upstream entrypoint runs `chown -R /storage` and that fails on
# a bind mount, killing the container. A symlink in plugins.core is picked up
# by the upstream storage setup and left alone by chown.
RUN ln -s /src /var/www/html/lib/plugins.core/drawio

# The third-party "odt" plugin (https://www.dokuwiki.org/plugin:odt) is what
# actually turns "Export to ODT" into a working feature - without it installed
# there is nothing for issue #7's code path to run against. Baking it into
# plugins.core (same trick as the drawio symlink above) means the upstream
# storage setup (see /dokuwiki-storagesetup.sh) links it into /storage/lib/plugins
# on every container start, so a leftover volume from before this line existed
# still ends up with it - no manual step, no docker-seed script for a human to
# forget to run.
#
# Pinned to the commit the 2023-03-03 tag pointed to when this was written -
# the same release dokuwiki.org's plugin page itself links to - rather than
# refs/heads/master or the tag name, because a tag is just a mutable ref: its
# owner (or GitHub, or an account compromise) can repoint it to different
# content without changing the URL below, and the build would silently pull
# it in. A commit SHA can't be moved. A checksum pin would guard the same
# thing, but for a dev-only image that never reaches a user's wiki (this
# layer runs on the maintainer's own machine and CI, not on anyone's
# production install) the SHA already gives an auditable, un-movable source -
# no separate checksum to keep in sync when bumping. Bump by picking a newer
# tag from https://github.com/LarsGit223/dokuwiki-plugin-odt/tags, resolving
# it to a commit SHA, and updating both the SHA and the extracted directory
# name below to match.
RUN apt-get update && apt-get install -y --no-install-recommends unzip \
    && curl -sL -o /tmp/odt.zip https://github.com/LarsGit223/dokuwiki-plugin-odt/archive/3722444bfad5b514ba1672f91a1dcdb0a8b57df6.zip \
    && unzip -q /tmp/odt.zip -d /tmp/odt \
    && mv /tmp/odt/dokuwiki-plugin-odt-3722444bfad5b514ba1672f91a1dcdb0a8b57df6 /var/www/html/lib/plugins.core/odt \
    && rm -rf /tmp/odt.zip /tmp/odt \
    && apt-get purge -y --auto-remove unzip \
    && rm -rf /var/lib/apt/lists/*

COPY seed/ /seed/
COPY dev-entrypoint.sh /dev-entrypoint.sh
ENTRYPOINT ["/dev-entrypoint.sh"]
