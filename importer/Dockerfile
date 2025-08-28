# syntax=docker.io/docker/dockerfile:1

FROM php:8.3.11-fpm-alpine3.20

RUN apk add --no-interactive git bash nano nodejs npm libzip-dev zip openssh \
    && npm install -g pnpm@^9.3.0 \
    && docker-php-ext-install zip

COPY --from=composer:2.5.8 /usr/bin/composer /usr/bin/composer
RUN composer config --global process-timeout 2000 \
    && adduser -D -u 1000 app

USER app
RUN git config --global user.email "documentation-builder@alchemy.fr"
RUN git config --global user.name "documentation-builder"

COPY --chown=app:app ./importer /srv/app
COPY --chown=app:app ./docusaurus/phrasea /srv/docusaurus/phrasea

USER app

WORKDIR /srv/docusaurus/phrasea
RUN pnpm install

WORKDIR /srv/app
RUN composer install --no-dev --optimize-autoloader

EXPOSE 3000

CMD ["php", "application.php", "import"]
