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
    listen __PORT__; \n\
    server_name _; \n\
    root /var/www/html/public; \n\
    index index.php; \n\
    client_max_body_size 1m; \n\
    location / { \n\
        try_files $uri $uri/ /index.php$is_args$args; \n\
    } \n\
    location ~ \\.php$ { \n\
        fastcgi_pass 127.0.0.1:9000; \n\
        fastcgi_index index.php; \n\
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; \n\
        fastcgi_param REQUEST_METHOD $request_method; \n\
        fastcgi_param CONTENT_TYPE $content_type; \n\
        fastcgi_param CONTENT_LENGTH $content_length; \n\
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

# startup: replace __PORT__, clear Symfony cache so Railway env vars are read fresh, then start
RUN echo '#!/bin/sh\n\
set -e\n\
sed -i "s/__PORT__/${PORT:-80}/g" /etc/nginx/sites-available/default\n\
\n\
# Clear Symfony cache at runtime so env vars injected by Railway are picked up\n\
APP_ENV=prod php /var/www/html/bin/console cache:clear --no-warmup --no-ansi 2>/dev/null || true\n\
APP_ENV=prod php /var/www/html/bin/console cache:warmup --no-ansi 2>/dev/null || true\n\
chown -R www-data:www-data /var/www/html/var\n\
\n\
php-fpm -D\n\
exec nginx -g "daemon off;"' > /start.sh && chmod +x /start.sh

EXPOSE 80

CMD ["/start.sh"]
