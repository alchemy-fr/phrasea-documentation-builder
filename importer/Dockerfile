# syntax=docker.io/docker/dockerfile:1

FROM php:8.3.11-fpm-alpine3.20 as importer
RUN apk add --no-interactive bash nano  \
      libzip-dev \
      zip \
    && docker-php-ext-install zip
COPY --from=composer:2.5.8 /usr/bin/composer /usr/bin/composer

RUN composer config --global process-timeout 2000 \
    && adduser -D -u 1000 app \
    && printf "\nuser = app\ngroup = app\n" >> /usr/local/etc/php-fpm.d/zz-docker.conf \
   # && composer install --no-dev --optimize-autoloader

USER app
WORKDIR /srv/app


#RUN composer install --no-dev --optimize-autoloader

CMD ["php-fpm"]
