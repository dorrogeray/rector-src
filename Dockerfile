FROM php:8-cli-alpine

ARG UID=1000
ARG GID=1000

WORKDIR /etc/rector

# required for composer patches
RUN apk add --no-cache --update \
        patch \
        shadow \
        autoconf \
        build-base \
        zlib-dev

# install and enable extension
RUN pecl install xdebug-3.1.4 \
    && docker-php-ext-enable xdebug

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN mkdir -p /etc/rector

RUN usermod -u $UID www-data && \
    groupmod -g $GID www-data

USER www-data
