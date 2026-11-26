FROM php:8.4-apache

# Install cron, timezone data, Composer build dependencies, and PHP extensions.
RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        cron \
        tzdata \
        git \
        unzip \
        libzip-dev \
        libcurl4-openssl-dev \
        libpq-dev \
    && docker-php-ext-install zip curl pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configure Apache on Render's expected port and enable security headers.
RUN a2enmod headers expires \
    && sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:10000>/' /etc/apache2/sites-available/000-default.conf \
    && printf '%s\n' \
        'Header always set X-Content-Type-Options "nosniff"' \
        'Header always set X-Frame-Options "SAMEORIGIN"' \
        'Header always set Referrer-Policy "strict-origin-when-cross-origin"' \
        'Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"' \
        > /etc/apache2/conf-available/pmsched-security.conf \
    && a2enconf pmsched-security \
    && printf '\nDirectoryIndex index.php\n' >> /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY composer.json /var/www/html/composer.json
RUN composer validate --no-check-publish \
    && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && composer clear-cache

COPY PMsched.php /var/www/html/index.php
RUN mkdir -p /var/data
COPY database.php /var/www/html/database.php
COPY database-bootstrap.php /var/www/html/database-bootstrap.php
COPY pm_schedule_data.json /var/www/html/pm_schedule_data.json
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    && chown -R www-data:www-data /var/www/html /var/data

ENV PORT=10000
ENV PM_TIMEZONE=Asia/Kolkata
EXPOSE 10000
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
