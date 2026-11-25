FROM php:8.6-fpm

# Build argument to determine dev or prod mode. It defaults to prod for the same
# reason the compose base does: a build that says nothing about which it wants
# must not be the one that ships a debugger.
ARG BUILD_MODE=prod

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    git \
    libicu-dev \
    libonig-dev \
    libxml2-dev \
    libssl-dev \
    mariadb-client \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libtool \
    # GD reads JPEG, PNG, GIF and WebP and writes WebP: hydrakit/image's
    # variants. EXIF tells it which way up a phone photo is.
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install \
       pdo \
       pdo_mysql \
       mysqli \
       zip \
       mbstring \
       intl \
       bcmath \
       $(php -m | grep -qi '^Zend OPcache$' || echo opcache) \
       pcntl \
       gd \
       exif \
    && docker-php-ext-enable pdo_mysql

# PIE (PHP Installer for Extensions) replaces pecl, which the 8.6 images no
# longer ship. Held to a major version, like Composer below.
COPY --from=ghcr.io/php/pie:1-bin /pie /usr/bin/pie

# Install Redis extension
RUN pie install "phpredis/phpredis:^6.4@RC"

# Install Xdebug and PCOV only in dev mode
RUN if [ "$BUILD_MODE" = "dev" ]; then \
        pie install "xdebug/xdebug:^3.6@alpha" && \
        { pie install pecl/pcov || echo "pcov: no build for this PHP, skipped"; }; \
    fi

# Install Composer. Held to a major version rather than :latest, so a rebuild
# cannot pick up a Composer that changes how the lock is read.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The repo is bind-mounted from the host (owned by the host user), so git's
# "dubious ownership" guard would otherwise block Composer (running as root)
# and the version read by FPM (running as www-data). System-wide for both.
RUN git config --system --add safe.directory '*'

# PHP-FPM pool config
COPY ./fpm-pool.conf /usr/local/etc/php-fpm.d/zz-pool.conf

# OPcache config (loaded first)
COPY ./opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Environment-specific PHP config is mounted at runtime via docker-compose
# (dev.ini or prod.ini as /usr/local/etc/php/conf.d/zz-env.ini)
