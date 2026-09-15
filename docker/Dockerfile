ARG DW_VERSION=stable
FROM dokuwiki/dokuwiki:${DW_VERSION}

# The plugin source is bind-mounted at /src. It must NOT be mounted inside
# /storage: the upstream entrypoint runs `chown -R /storage` and that fails on
# a bind mount, killing the container. A symlink in plugins.core is picked up
# by the upstream storage setup and left alone by chown.
RUN ln -s /src /var/www/html/lib/plugins.core/drawio

COPY seed/ /seed/
COPY dev-entrypoint.sh /dev-entrypoint.sh
ENTRYPOINT ["/dev-entrypoint.sh"]
