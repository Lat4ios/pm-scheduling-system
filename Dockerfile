
FROM php:8.4-apache

# Install system dependencies and PHP extensions
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libcurl4-openssl-dev \
    && docker-php-ext-install zip curl \
    && rm -rf /var/lib/apt/lists/*

# Copy Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configure Apache
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

# Set application directory
WORKDIR /var/www/html

# Install Composer dependencies
COPY composer.json /var/www/html/composer.json

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    && composer clear-cache

# Copy application
COPY PMsched.php /var/www/html/index.php

# Prepare persistent data and seed files
RUN mkdir -p /opt/pmsched-seed /var/data

COPY pm_schedule_data.json /opt/pmsched-seed/pm_schedule_data.json
COPY pm_user_photos/ /opt/pmsched-seed/pm_user_photos/

# Copy Docker entrypoint
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    && chown -R www-data:www-data /var/www/html /var/data

# Render configuration
ENV PORT=10000

EXPOSE 10000

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]

CMD ["apache2-foreground"]
