FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        default-mysql-client curl cron libfreetype6-dev libjpeg62-turbo-dev \
        libpng-dev libzip-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd pdo pdo_mysql mysqli zip \
    && a2enmod rewrite headers expires \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html
COPY infrastructure/panel/entrypoint.sh /usr/local/bin/jm-panel-entrypoint
COPY infrastructure/panel/apache.conf /etc/apache2/conf-available/jm-panel.conf

RUN chmod +x /usr/local/bin/jm-panel-entrypoint \
    && a2enconf jm-panel \
    && mkdir -p /var/www/html/system/uploads /var/www/html/system/cache \
       /var/www/html/ui/compiled /var/www/html/system/secure \
    && chown -R www-data:www-data /var/www/html

WORKDIR /var/www/html
EXPOSE 80
ENTRYPOINT ["jm-panel-entrypoint"]
CMD ["apache2-foreground"]
