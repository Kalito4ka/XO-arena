FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY apache/000-default.conf /etc/apache2/sites-available/000-default.conf

COPY public/ /var/www/html/public/
COPY src/ /var/www/html/src/

EXPOSE 80