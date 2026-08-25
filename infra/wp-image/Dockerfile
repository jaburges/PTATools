# Production WordPress image for wilderptsa.net on Azure Container Apps.
#
# The image is immutable: WordPress core comes from the official base, and
# wp-content (theme, plugins, mu-plugins) is baked in at build time. Uploads are
# NOT in the image — they live on an Azure Files share mounted at
# wp-content/uploads, because the blob media offload on App Service was a
# platform feature with no equivalent in a plain container.
#
# Because the filesystem is immutable, OPcache runs with validate_timestamps=0.
# That matters more than it looks: this site loads ~1,800-2,300 PHP files per
# request, and skipping revalidation removes that many stat() calls from every
# hit.

# Core version is set here and nowhere else. Updating from wp-admin cannot
# persist — core lives in this image layer, not on the uploads share — so bumping
# this tag is how the site's WordPress version changes.
FROM wordpress:7.0.2-php8.3-apache

# unzip is needed by WordPress for plugin/theme operations; the rest of the
# base image already carries the required PHP extensions.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends unzip less curl ca-certificates; \
    rm -rf /var/lib/apt/lists/*

# phpredis backs the object cache drop-in. The build toolchain is installed only
# to compile it and is removed again so it does not ship in the final image.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS; \
    pecl install redis-6.1.0; \
    docker-php-ext-enable redis; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/* /tmp/pear; \
    php -m | grep -qx redis

COPY php-opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY php-wordpress.ini /usr/local/etc/php/conf.d/zz-wordpress.ini
COPY apache-wp.conf /etc/apache2/conf-enabled/zz-wordpress.conf

# wp-content is supplied by the build (see build.sh, which unpacks the export
# from the migration container). Only themes, plugins and mu-plugins — uploads
# are excluded deliberately.
COPY --chown=www-data:www-data wp-content/themes/     /var/www/html/wp-content/themes/
COPY --chown=www-data:www-data wp-content/plugins/    /var/www/html/wp-content/plugins/
COPY --chown=www-data:www-data wp-content/mu-plugins/ /var/www/html/wp-content/mu-plugins/

# Hides the core update offer and explains why. Copied after the export so it
# cannot be shadowed by a stale copy from the source site.
COPY --chown=www-data:www-data mu-plugins/ /var/www/html/wp-content/mu-plugins/

# The plugin ships from this repository rather than from the exported site, so
# the released version wins over whatever the source install happened to run.
# JSON form is required: the destination contains a space, which the shell form
# of COPY cannot express.
COPY --chown=www-data:www-data ["azure-plugin/", "/var/www/html/wp-content/plugins/Azure Plugin/"]

# Redis object cache. The drop-in has to be baked in rather than installed from
# wp-admin: wp-content is part of the image, so anything the plugin writes at
# runtime would vanish on the next restart.
ARG REDIS_CACHE_VERSION=2.8.0
RUN set -eux; \
    curl -fSL -o /tmp/redis-cache.zip \
      "https://downloads.wordpress.org/plugin/redis-cache.${REDIS_CACHE_VERSION}.zip"; \
    unzip -q /tmp/redis-cache.zip -d /var/www/html/wp-content/plugins/; \
    rm -f /tmp/redis-cache.zip; \
    cp /var/www/html/wp-content/plugins/redis-cache/includes/object-cache.php \
       /var/www/html/wp-content/object-cache.php; \
    test -f /var/www/html/wp-content/object-cache.php

# Multiple Roles: checklist UI on the user edit screen. Same reason as
# redis-cache — a wp-admin install lands on ephemeral disk and disappears
# on the next revision.
ARG MULTIPLE_ROLES_VERSION=1.3.8
RUN set -eux; \
    curl -fSL -o /tmp/multiple-roles.zip \
      "https://downloads.wordpress.org/plugin/multiple-roles.${MULTIPLE_ROLES_VERSION}.zip"; \
    unzip -q /tmp/multiple-roles.zip -d /var/www/html/wp-content/plugins/; \
    rm -f /tmp/multiple-roles.zip; \
    test -f /var/www/html/wp-content/plugins/multiple-roles/multiple-roles.php

# Keep one core theme so WordPress has something to fall back to if the active
# theme raises a fatal error. Without it a broken theme means a blank site with
# no route back into wp-admin. Taken from the base image rather than the export,
# which only carried the active theme.
RUN set -eux; \
    cp -R /usr/src/wordpress/wp-content/themes/twentytwentyfive \
          /var/www/html/wp-content/themes/twentytwentyfive

# Belt-and-suspenders: Mac resource-fork files must never ship. A
# mu-plugins/._*.php sidecar is treated as a real plugin and printed on
# every request (visible as "Mac OS X … com.apple.provenance" in the header).
RUN find /var/www/html/wp-content \( -name '._*' -o -name '.DS_Store' \) -delete

# Create the mount point so Apache has a valid uploads path even before the
# Azure Files share attaches.
RUN set -eux; \
    mkdir -p /var/www/html/wp-content/uploads; \
    printf '<?php\n// Silence is golden.\n' > /var/www/html/wp-content/index.php; \
    printf '<?php\n// Silence is golden.\n' > /var/www/html/wp-content/plugins/index.php; \
    printf '<?php\n// Silence is golden.\n' > /var/www/html/wp-content/themes/index.php; \
    chown -R www-data:www-data /var/www/html/wp-content; \
    a2enmod rewrite expires headers

# Report readiness without invoking PHP, so the probe stays cheap.
COPY healthz.php /var/www/html/healthz.php

EXPOSE 80
