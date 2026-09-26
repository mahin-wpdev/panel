FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        default-mysql-client curl cron libcurl4-openssl-dev libonig-dev libfreetype6-dev libjpeg62-turbo-dev \
        libpng-dev libzip-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" curl mbstring gd pdo pdo_mysql mysqli zip \
    && a2enmod rewrite headers expires \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html
COPY infrastructure/panel/entrypoint.sh /usr/local/bin/jm-panel-entrypoint
COPY infrastructure/panel/run-migrations.sh /usr/local/bin/jm-panel-migrate
COPY infrastructure/cron/worker.sh /usr/local/bin/jm-cron-worker
COPY infrastructure/panel/apache.conf /etc/apache2/conf-available/jm-panel.conf

RUN chmod +x /usr/local/bin/jm-panel-entrypoint /usr/local/bin/jm-panel-migrate /usr/local/bin/jm-cron-worker \
    && a2enconf jm-panel \
    && mkdir -p /var/www/html/system/uploads /var/www/html/system/cache \
       /var/www/html/ui/compiled /var/www/html/system/secure \
    && chown -R www-data:www-data /var/www/html

WORKDIR /var/www/html
EXPOSE 80
ENTRYPOINT ["jm-panel-entrypoint"]
CMD ["apache2-foreground"]
