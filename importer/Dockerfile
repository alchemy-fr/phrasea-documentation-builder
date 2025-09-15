# syntax=docker.io/docker/dockerfile:1
ARG PHRASEA_TAG=latest
# FROM ghcr.io/alchemy-fr/fake-phrasea:${PHRASEA_TAG} AS phrasea

FROM php:8.3.11-cli-alpine3.20 AS importer

RUN apk add --no-interactive git bash nano nodejs npm \
    && npm install -g pnpm@^9.3.0


COPY --from=composer:2.5.8 /usr/bin/composer /usr/bin/composer
RUN composer config --global process-timeout 2000 \
&& adduser -D -u 1000 app

USER app
#        RUN git config --global user.email "documentation-builder@alchemy.fr"
#        RUN git config --global user.name "documentation-builder"
#
COPY --chown=app:app ./importer /srv/app
COPY --chown=app:app ./downloads /srv/app/downloads
RUN tree /srv/app/downloads
WORKDIR /srv/app
#        COPY --chown=app:app ./docusaurus/phrasea /srv/docusaurus/phrasea
#
#        # ! COPY --from does not support variable substitution (see https://github.com/moby/moby/issues/34482)
#        # COPY --from=phrasea --chown=app:app /srv/app/doc /srv/app/downloads/doc
#
#
#
#        # RUN ls -al /srv/app/downloads/doc/_generatedDoc
#
#        USER app
#
#        WORKDIR /srv/docusaurus/phrasea
#        RUN pnpm install
#
WORKDIR /srv/app
RUN composer install --no-dev --optimize-autoloader

RUN ["php", "application.php", "build"]

# FROM php:8.3.11-fpm-alpine3.20
# COPY --from=importer /srv/app/build/ /var/www/html/
