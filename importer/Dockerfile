# syntax=docker.io/docker/dockerfile:1
# FROM ghcr.io/alchemy-fr/fake-phrasea:${PHRASEA_TAG} AS phrasea
FROM php:8.3.11-cli-alpine3.20 AS importer

ARG PHRASEA_REFNAME=master
ARG PHRASEA_REFTYPE=branch
ARG PHRASEA_DATETIME=20000101000000

RUN apk add --no-interactive git bash nano nodejs npm \
    && npm install -g pnpm@^9.3.0

COPY --from=composer:2.5.8 /usr/bin/composer /usr/bin/composer
RUN composer config --global process-timeout 2000 \
&& adduser -D -u 1000 app

USER app

COPY --chown=app:app ./importer /srv/app
COPY --chown=app:app ./docusaurus/phrasea /srv/docusaurus/phrasea
COPY --chown=app:app ./docusaurus/nginx /srv/docusaurus/nginx

WORKDIR /srv/docusaurus/phrasea
RUN pnpm install

WORKDIR /srv/app
RUN composer install --no-dev --optimize-autoloader

RUN ["php", "application.php", "-vvv", "build"]

WORKDIR /srv/docusaurus/phrasea
RUN ["pnpm", "run", "build" ]

FROM nginx:1.29-alpine
ARG PHRASEA_REFTYPE
COPY --from=importer /srv/docusaurus/nginx/headers-${PHRASEA_REFTYPE}.conf /etc/nginx/conf.d/
COPY --from=importer /srv/docusaurus/phrasea/build/ /usr/share/nginx/html/

