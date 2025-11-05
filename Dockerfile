FROM php:8.4.14-cli-alpine3.21 AS builder

ARG PHRASEA_REFNAME
ARG PHRASEA_REFTYPE
ARG PHRASEA_DATETIME
ARG SITE_NAME="Phrasea Documentation"
ARG SITE_URL="https://doc.phrasea.com"

RUN apk add --no-interactive \
        git \
        nodejs \
        npm \
    && npm install -g pnpm@^10.20.0 \
    && adduser -D -u 1000 app

COPY --from=composer:2.5.8 /usr/bin/composer /usr/bin/composer

USER app

COPY --chown=app:app ./docusaurus/phrasea /srv/docusaurus/phrasea

WORKDIR /srv/docusaurus/phrasea

RUN pnpm install

WORKDIR /srv/builder

COPY --chown=app:app ./builder /srv/builder

RUN composer install --no-dev --optimize-autoloader

FROM builder AS build-docs

COPY --chown=app:app ./downloads /srv/downloads

RUN ["php", "application.php", "-vvv", "build", "/srv/docusaurus/phrasea", "/srv/downloads"]

FROM nginx:1.29.3-alpine3.22

ARG PHRASEA_REFTYPE

ENV URL=https://doc.phrasea.com

COPY ./docusaurus/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY ./docusaurus/nginx/headers-${PHRASEA_REFTYPE}.conf /etc/nginx/conf.d/
COPY --from=build-docs /srv/docusaurus/phrasea/build/ /usr/share/nginx/html/
