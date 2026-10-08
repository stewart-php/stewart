# syntax=docker/dockerfile:1
ARG PHP_VERSION=8.5
ARG ALPINE_VERSION=3.24
ARG COMPOSER_VERSION=2.10
ARG EXTENSION_INSTALLER_VERSION=2.11
ARG RUNTIME_UID=10001

FROM composer:${COMPOSER_VERSION} AS composer

FROM mlocati/php-extension-installer:${EXTENSION_INSTALLER_VERSION} AS extension-installer

# Published as ghcr.io/stewart-php/runtime: PHP for a Stewart project. The framework comes from the project's vendor/.
FROM php:${PHP_VERSION}-cli-alpine${ALPINE_VERSION} AS runtime

ARG RUNTIME_UID

COPY --from=extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN install-php-extensions \
        pcntl \
        sockets \
        zlib \
        opcache \
    && apk add --no-cache \
        ca-certificates \
        git \
        openssh-client \
        tini \
        unzip \
    && addgroup -g ${RUNTIME_UID} -S stewart \
    && adduser -u ${RUNTIME_UID} -S -G stewart -h /app -H stewart \
    && rm -rf /tmp/* \
    && mkdir -p /app /tmp/composer \
    && chown ${RUNTIME_UID}:${RUNTIME_UID} /app /tmp/composer

COPY .docker/php/conf.d/stewart.ini /usr/local/etc/php/conf.d/zz-stewart.ini
COPY .docker/php/conf.d/prod.ini /usr/local/etc/php/conf.d/zzz-prod.ini
COPY --chmod=755 .docker/runtime/entrypoint.sh /usr/local/bin/stewart-entrypoint

ENV HOME=/tmp \
    COMPOSER_HOME=/tmp/composer \
    PATH=/app/current/vendor/bin:/app/vendor/bin:$PATH

LABEL org.opencontainers.image.title="Stewart runtime" \
      org.opencontainers.image.description="PHP, extensions and an entrypoint for running a Stewart project" \
      org.opencontainers.image.source="https://github.com/stewart-php/stewart" \
      org.opencontainers.image.licenses="MIT"

WORKDIR /app
USER ${RUNTIME_UID}:${RUNTIME_UID}

ENTRYPOINT ["/sbin/tini", "--", "stewart-entrypoint"]
CMD ["run"]

FROM runtime AS runtime-dev

ARG RUNTIME_UID

USER root

RUN install-php-extensions xdebug \
    && rm -f /usr/local/etc/php/conf.d/zzz-prod.ini

COPY .docker/php/conf.d/xdebug.ini /usr/local/etc/php/conf.d/zz-xdebug.ini

USER ${RUNTIME_UID}:${RUNTIME_UID}

# The monorepo toolchain: plain commands, no boot steps.
FROM runtime-dev AS dev

ENTRYPOINT []
CMD ["sh"]
