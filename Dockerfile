FROM php:8.3-apache

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install bcmath zip \
    && rm -f /etc/apache2/mods-enabled/mpm_event.* \
              /etc/apache2/mods-enabled/mpm_worker.* \
              /etc/apache2/mods-enabled/mpm_prefork.* \
    && ln -s /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load \
    && ln -s /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

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

RUN mkdir -p var/cache var/log var/secrets var/idempotency \
    && composer dump-autoload --classmap-authoritative --no-dev \
    && chown -R www-data:www-data /var/www/html \
    && chmod 755 /var/www/html/public

EXPOSE 80
