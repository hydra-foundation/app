FROM php:8.4-fpm

# Build argument to determine dev or prod mode
ARG BUILD_MODE=dev

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
    && docker-php-ext-install \
       pdo \
       pdo_mysql \
       mysqli \
       zip \
       mbstring \
       intl \
       bcmath \
       opcache \
    && docker-php-ext-enable pdo_mysql

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install Xdebug and PCOV only in dev mode
RUN if [ "$BUILD_MODE" = "dev" ]; then \
        pecl install xdebug && docker-php-ext-enable xdebug && \
        pecl install pcov && docker-php-ext-enable pcov; \
    fi

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# The repo is bind-mounted from the host (owned by the host user), but the
# container runs as root — git's "dubious ownership" guard would otherwise
# block Composer from reading the package's VCS metadata.
RUN git config --global --add safe.directory '*'

# PHP-FPM pool config
COPY ./fpm-pool.conf /usr/local/etc/php-fpm.d/zz-pool.conf

# OPcache config (loaded first)
COPY ./opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Environment-specific PHP config is mounted at runtime via docker-compose
# (dev.ini or prod.ini as /usr/local/etc/php/conf.d/zz-env.ini)
