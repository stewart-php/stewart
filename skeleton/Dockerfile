# syntax=docker/dockerfile:1
FROM ghcr.io/stewart-php/runtime:0.5 AS build

COPY --chown=10001:10001 composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader

COPY --chown=10001:10001 . .
RUN composer dump-autoload --no-dev --optimize

FROM ghcr.io/stewart-php/runtime:0.5

COPY --from=build /app /app

# Dependencies are part of the image, so the entrypoint never installs them.
ENV STEWART_BOOT_COMPOSER=never
