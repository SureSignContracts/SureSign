FROM php:8.3-cli

# Static-only OCI labels — reliably populable without any build-arg plumbing.
# Deliberately omitting org.opencontainers.image.revision/created: those need
# a build-time --build-arg (e.g. `git rev-parse HEAD`) that isn't currently
# wired into docker-compose.prod.yml's `build:` section, and Dokploy's own
# build invocation supplying it correctly isn't confirmed — recommended as a
# follow-up, not guessed at here.
LABEL org.opencontainers.image.title="SureSign Backend" \
      org.opencontainers.image.description="SureSign Laravel API — also used by the queue and scheduler services"

# Enable contrib repo for MS fonts
RUN sed -i 's/Components: main$/Components: main contrib non-free/' /etc/apt/sources.list.d/debian.sources 2>/dev/null || \
    sed -i 's/ main$/ main contrib non-free/' /etc/apt/sources.list 2>/dev/null || true

# Accept MS core fonts EULA non-interactively
RUN echo "ttf-mscorefonts-installer msttcorefonts/accepted-mscorefonts-eula select true" | debconf-set-selections

RUN apt-get update && apt-get install -y \
    git curl zip unzip libpng-dev libonig-dev libxml2-dev libzip-dev \
    libreoffice \
    ttf-mscorefonts-installer \
    fonts-liberation \
    fonts-dejavu \
    fonts-freefont-ttf \
    fontconfig \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip \
    && fc-cache -f -v \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Production-safe by default (no dev dependencies, no interactive prompts).
# docker-compose.dev.yml overrides this build arg to include dev tooling
# (phpunit, mockery, faker, pint) needed for local testing.
ARG COMPOSER_INSTALL_FLAGS="--no-dev --no-interaction --prefer-dist"

# Copy only the manifests first so this (slow — network download/extract)
# layer is cached across builds unless composer.json/composer.lock
# actually change, instead of being invalidated by every source edit.
# --no-scripts --no-autoloader is required here: composer.json's
# post-autoload-dump hook runs `php artisan package:discover`, which needs
# the full app tree (bootstrap/app.php, app/, config/) to bootstrap
# Laravel — none of which exists yet at this point in the build. Autoload
# generation + package discovery happen later, once the full source is
# present (see the composer dump-autoload step below).
COPY composer.json composer.lock ./
RUN composer install ${COMPOSER_INSTALL_FLAGS} --no-scripts --no-autoloader

COPY . .

RUN mkdir -p bootstrap/cache storage/app/public storage/app/private \
        storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && chmod -R 775 bootstrap/cache storage

COPY docker/php.ini /usr/local/etc/php/conf.d/suresign-uploads.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Now that the full app is present: generate the optimised autoloader
# (triggers post-autoload-dump automatically, which already calls
# package:discover — the explicit call below is belt-and-braces in case
# that composer.json hook is ever removed, and costs nothing extra since
# it's already-cached/idempotent).
# `php artisan package:discover` (run as root, before the ownership fix
# below) writes bootstrap/cache/packages.php + services.php — the chown
# below has to run AFTER this, not before, or those two files would end up
# root-owned again despite the earlier mkdir's ownership.
RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi

# Everything the running app needs to write (Laravel logs, framework
# cache/session/view cache, compiled bootstrap cache) is image-local at
# this point — chowning it here at build time is a one-off, bounded-size
# operation, not a runtime chown of the (potentially large, potentially
# pre-existing) storage/app named volume, which is deliberately NOT touched
# here — see the separate one-time volume-ownership migration for that.
#
# www-data's own $HOME (/var/www, a Debian default — see /etc/passwd) is
# root-owned by default; not needed by the running app itself, but
# `php artisan tinker` (PsySH) tries to write its own config/history there
# on every invocation and fails hard otherwise — this only matters for
# operator debugging (`docker exec ... php artisan tinker`), not for any
# of the actual long-running processes, but it's a one-line, single
# (non-recursive) directory fix so it's worth doing.
RUN chown -R www-data:www-data bootstrap/cache storage public \
    && chown www-data:www-data /var/www

EXPOSE 8000
USER www-data
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
