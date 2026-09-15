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
# Pinned to the 2023-03-03 tag - the same release dokuwiki.org's plugin page
# itself links to - rather than refs/heads/master, so a push to that branch
# (or a GitHub outage) can't silently change _odtAddImage()'s contract or
# break `docker compose up --build` for people who never touch ODT. Bump by
# picking a newer tag from https://github.com/LarsGit223/dokuwiki-plugin-odt/tags
# and updating both the tag and the extracted directory name below to match.
RUN apt-get update && apt-get install -y --no-install-recommends unzip \
    && curl -sL -o /tmp/odt.zip https://github.com/LarsGit223/dokuwiki-plugin-odt/archive/refs/tags/2023-03-03.zip \
    && unzip -q /tmp/odt.zip -d /tmp/odt \
    && mv /tmp/odt/dokuwiki-plugin-odt-2023-03-03 /var/www/html/lib/plugins.core/odt \
    && rm -rf /tmp/odt.zip /tmp/odt \
    && apt-get purge -y --auto-remove unzip \
    && rm -rf /var/lib/apt/lists/*

COPY seed/ /seed/
COPY dev-entrypoint.sh /dev-entrypoint.sh
ENTRYPOINT ["/dev-entrypoint.sh"]
