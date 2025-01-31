FROM php:8.3-fpm AS builder

# Install build dependencies
RUN apt-get -qq update && apt-get -qq -y install \
  automake \
  cmake \
  g++ \
  git \
  libicu-dev \
  libmagickwand-dev \
  libreadline-dev \
  libzip-dev \
  zlib1g-dev \
  libxml2-dev \
  pkg-config \
  gnupg \
  libgpgme11-dev

# Install PHP extensions
RUN pecl install gnupg redis xdebug && \
    docker-php-ext-enable gnupg redis

RUN docker-php-ext-install \
    bcmath \
    intl \
    opcache \
    pdo_mysql \
    sockets \
    zip

# Install GD with required dependencies
RUN apt-get install -y libfreetype6-dev libjpeg62-turbo-dev libpng-dev && \
    docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install gd && \
    docker-php-ext-enable gd

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Final stage
FROM php:8.3-fpm

# Copy PHP extensions and configurations from builder
COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=builder /usr/local/bin/composer /usr/local/bin/composer

# Install runtime dependencies only
RUN apt-get -qq update && apt-get -qq -y install \
    gnupg \
    libgpgme11 \
    libicu72 \
    libzip4 \
    libpng16-16 \
    libfreetype6 \
    libjpeg62-turbo \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/app

# Set up GPG directories with proper permissions
RUN mkdir -p /var/www/app/config/pgp/{key-config,private-keys-v1.d,openpgp-revocs.d} && \
    chmod -R 700 /var/www/app/config/pgp && \
    chown -R www-data:www-data /var/www/app/config/pgp && \
    mkdir -p /var/www/app/config/pgp/key-config

# Configure GPG for non-interactive mode and security
RUN echo "use-agent" > /var/www/app/config/pgp/key-config/gpg.conf && \
    echo "pinentry-mode loopback" >> /var/www/app/config/pgp/key-config/gpg.conf && \
    echo "no-emit-version" >> /var/www/app/config/pgp/key-config/gpg.conf && \
    echo "no-comments" >> /var/www/app/config/pgp/key-config/gpg.conf && \
    echo "export-options export-minimal" >> /var/www/app/config/pgp/key-config/gpg.conf
