# docker/php/Dockerfile
# Local development image only. Never used by CI/CD — deploy.yaml and
# deploy-preprod.yaml still build with shivammathur/setup-php on
# ubuntu-latest and rsync straight to IONOS, unchanged.
FROM php:8.4-cli-bookworm

# System packages needed to build the PHP extensions Laravel/amana-shared/
# google-apiclient use, plus git (composer VCS repo + npm git dependency)
# and a mysql client (handy for `mysql` one-liners / wait-for-db checks).
# libsqlite3-dev is required by pdo_sqlite's configure step (missing it is
# what the SQLITE_CFLAGS error is complaining about); libjpeg/libpng/
# libwebp/libfreetype are required by gd, which also needs an explicit
# `docker-php-ext-configure` call before `docker-php-ext-install` — unlike
# the other extensions here, gd doesn't build with sane defaults alone.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip zip \
        libzip-dev libpng-dev libonig-dev libxml2-dev libicu-dev \
        libsqlite3-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
        default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql pdo_sqlite mbstring exif pcntl bcmath gd intl zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Runs as root, deliberately. This is a local-dev-only container on your
# own machine, never shipped anywhere — an earlier version of this image
# ran as a non-root user matched to your host UID/GID, which sounds nice
# in principle but turns into a permissions maze: Docker creates named
# volumes (vendor/, node_modules/) owned by root, so a non-root process
# can't write into them (`vendor/wikimedia does not exist and could not
# be created` is exactly that). Root sidesteps the whole class of bug.
# The one place this matters — files Laravel writes into the *bind-mounted*
# storage/ and bootstrap/cache/, which really would end up root-owned on
# your host — the entrypoint chowns back to your host UID/GID as its last
# step, so `git status`/editing those files outside Docker still works
# without sudo (see docker/php/entrypoint.sh).
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
