FROM php:8.3-fpm

WORKDIR /var/www/app

RUN apt-get -qq update && apt-get -qq -y install  \
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
  ssh-client \
  cron \
  vim \
  && docker-php-ext-install \
  bcmath \
  intl \
  opcache \
  pdo_mysql \
  sockets \
  zip \
  && pecl install \
  redis \
  xdebug \
  && docker-php-ext-enable \
  redis

RUN apt-get install -y libfreetype6-dev libjpeg62-turbo-dev libpng-dev
RUN docker-php-ext-configure gd --with-freetype --with-jpeg
RUN docker-php-ext-install gd
RUN docker-php-ext-enable gd

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer