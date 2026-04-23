FROM php:8.3-apache

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install bcmath zip \
    && a2dismod mpm_event mpm_worker || true \
    && a2enmod mpm_prefork rewrite \
    && rm -rf /var/lib/apt/lists/*

# Write a clean vhost — no sed hacks
RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html/public\n\
    <Directory /var/www/html/public>\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

COPY composer.json composer.lock symfony.lock ./

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --no-scripts

COPY . .

# Create ALL required runtime dirs BEFORE chown
RUN mkdir -p var/cache var/log var/secrets var/idempotency \
    && composer dump-autoload --classmap-authoritative --no-dev \
    && chown -R www-data:www-data /var/www/html \
    && chmod 755 /var/www/html/public

EXPOSE 80
