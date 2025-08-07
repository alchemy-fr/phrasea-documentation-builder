# syntax=docker.io/docker/dockerfile:1

FROM php:8.3.11-fpm-alpine3.20

RUN apk add --no-interactive bash nano nodejs npm libzip-dev zip \
    && npm install -g pnpm@^9.3.0 \
    && docker-php-ext-install zip

COPY --from=composer:2.5.8 /usr/bin/composer /usr/bin/composer
RUN composer config --global process-timeout 2000 \
    && adduser -D -u 1000 app

COPY --chown=app:app ./importer /srv/app
COPY --chown=app:app ./docusaurus/phrasea /srv/docusaurus/phrasea

USER app

WORKDIR /srv/app
RUN composer install --no-dev --optimize-autoloader
RUN php application.php import

WORKDIR /srv/docusaurus/phrasea
# RUN pnpm install && pnpm run gen-api-docs databox && pnpm build
RUN pnpm install && pnpm build

EXPOSE 3000

CMD ["pnpm", "serve"]
