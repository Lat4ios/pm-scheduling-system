FROM php:8.4-apache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN a2enmod headers expires \
    && sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:10000>/' /etc/apache2/sites-available/000-default.conf \
    && printf '\nDirectoryIndex index.php\n' >> /etc/apache2/apache2.conf \
    && printf '\nHeader always set X-Content-Type-Options "nosniff"\nHeader always set X-Frame-Options "SAMEORIGIN"\nHeader always set Referrer-Policy "strict-origin-when-cross-origin"\nHeader always set Strict-Transport-Security "max-age=31536000; includeSubDomains"\n' > /etc/apache2/conf-available/pmsched-security.conf \
    && a2enconf pmsched-security

WORKDIR /var/www/html
COPY composer.json /var/www/html/composer.json
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && composer clear-cache
COPY PMsched.php /var/www/html/index.php
# Seed data is stored outside Apache's public document root.
RUN mkdir -p /opt/pmsched-seed /var/data
COPY pm_schedule_data.json /opt/pmsched-seed/pm_schedule_data.json
COPY pm_user_photos/ /opt/pmsched-seed/pm_user_photos/
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    && chown -R www-data:www-data /var/www/html /var/data

ENV PORT=10000
EXPOSE 10000
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
