# eduQR — local development image (PHP 8.3 + Apache) [NFR-75]
#
# Apache rather than PHP-FPM: the app ships public/.htaccess with the
# front-controller rewrite and the security headers it depends on, so the
# document root must be served by a web server that honours .htaccess.
FROM php:8.3-apache-bookworm

# PHP extensions required by composer.json (ext-gd, ext-intl, ext-mbstring,
# ext-json, ext-pdo) plus pdo_mysql for src/Support/Database.php.
# mbstring and json ship enabled in the official image.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd intl zip \
    && rm -rf /var/lib/apt/lists/*

# Production php.ini as the base; raise upload limits for question images (10 MB).
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'upload_max_filesize=12M\npost_max_size=12M\n' > "$PHP_INI_DIR/conf.d/eduqr.ini"

# Serve public/ only, honour .htaccess, listen on an unprivileged port.
COPY deploy/docker-apache.conf /etc/apache2/sites-available/000-default.conf
RUN a2enmod rewrite headers \
    && sed -ri 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf

COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

# Dev dependencies are installed on purpose: this image also runs
# `composer test` and `composer lint`.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize

COPY deploy/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

RUN mkdir -p /var/www/html/logs /var/www/html/public/uploads \
             /var/run/apache2 /var/lock/apache2 /var/log/apache2 \
    && chown -R www-data:www-data \
        /var/www/html /var/run/apache2 /var/lock/apache2 /var/log/apache2

USER www-data
EXPOSE 8080

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
