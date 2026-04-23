FROM php:8.3-fpm

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
       git unzip libzip-dev nginx \
    && docker-php-ext-install bcmath zip \
    && rm -rf /var/lib/apt/lists/*

# nginx config
RUN echo 'server { \n\
    listen 80; \n\
    root /var/www/html/public; \n\
    index index.php; \n\
    location / { \n\
        try_files $uri $uri/ /index.php$is_args$args; \n\
    } \n\
    location ~ \.php$ { \n\
        fastcgi_pass 127.0.0.1:9000; \n\
        fastcgi_index index.php; \n\
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; \n\
        include fastcgi_params; \n\
    } \n\
}' > /etc/nginx/sites-available/default

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
    && chown -R www-data:www-data /var/www/html

# startup script: run nginx + php-fpm together
RUN echo '#!/bin/sh\nnginx\nphp-fpm' > /start.sh && chmod +x /start.sh

EXPOSE 80

CMD ["/start.sh"]
