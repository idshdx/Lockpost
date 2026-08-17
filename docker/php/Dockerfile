ARG APP_ENV=dev

# ─── Builder Stage ───
# The php:8.3-fpm image runs as root by design. PHP-FPM's master process needs
# root to bind ports and manage worker processes; it drops privileges to
# www-data for worker pools via the 'user' directive in www.conf.
# The entrypoint.sh handles runtime permission fixing before starting PHP-FPM,
# ensuring the container is not left running with unnecessary privileges in
# a writable state. This is the standard Docker PHP-FPM security pattern.
FROM php:8.3-fpm AS builder

# Install build dependencies
RUN apt-get -qq update && apt-get -qq -y --no-install-recommends install \
  automake \
  cmake \
  g++ \
  git \
  libicu-dev \
  libreadline-dev \
  libzip-dev \
  zlib1g-dev \
  libxml2-dev \
  pkg-config \
  gnupg \
  libgpgme11-dev

# Install PHP extensions (only what the app needs: bcmath, intl, opcache, sockets, zip)
RUN pecl install gnupg && \
    docker-php-ext-enable gnupg

RUN docker-php-ext-install \
    bcmath \
    intl \
    opcache \
    sockets \
    zip && \
    docker-php-ext-enable opcache

# Install Composer (using official image — avoids insecure curl|php pipeline)
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# ─── Development-only Stage: Xdebug ───
FROM builder AS dev

RUN pecl install xdebug && \
    docker-php-ext-enable xdebug

# Copy all custom PHP configs (same as production, plus xdebug)
COPY conf.d/xdebug.ini /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
COPY conf.d/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY conf.d/gnupg.ini /usr/local/etc/php/conf.d/gnupg.ini
COPY conf.d/realpath_cache.ini /usr/local/etc/php/conf.d/realpath_cache.ini

# ─── Final Stage (Production) ───
FROM php:8.3-fpm AS final

# Copy PHP extensions and configurations from builder
COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=builder /usr/local/bin/composer /usr/local/bin/composer

# Copy custom PHP extension configs (baked into image for prod consistency)
COPY conf.d/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY conf.d/gnupg.ini /usr/local/etc/php/conf.d/gnupg.ini
COPY conf.d/realpath_cache.ini /usr/local/etc/php/conf.d/realpath_cache.ini

# Install runtime dependencies only
RUN apt-get -qq update && apt-get -qq -y --no-install-recommends install \
    gnupg \
    libgpgme11 \
    libicu-dev \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/app

# Set up GPG directories with proper permissions, pre-create var/ dirs.
# All RUN instructions are merged into one for fewer image layers.
RUN mkdir -p /var/www/app/config/pgp/{key-config,private-keys-v1.d,openpgp-revocs.d} && \
    mkdir -p /var/www/app/var/cache /var/www/app/var/log && \
    chmod -R 700 /var/www/app/config/pgp/key-config && \
    chown -R www-data:www-data /var/www/app/config/pgp /var/www/app/var && \
    echo "use-agent" > /var/www/app/config/pgp/key-config/gpg.conf && \
    echo "pinentry-mode loopback" >> /var/www/app/config/pgp/key-config/gpg.conf && \
    echo "no-emit-version" >> /var/www/app/config/pgp/key-config/gpg.conf && \
    echo "no-comments" >> /var/www/app/config/pgp/key-config/gpg.conf && \
    echo "export-options export-minimal" >> /var/www/app/config/pgp/key-config/gpg.conf

# Entrypoint: fixes var/ and config/pgp/ ownership before PHP-FPM starts.
# PHP-FPM's master process runs as root (required for port binding / signal handling)
# and drops to www-data for worker pools via the 'user' directive in www.conf.
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
