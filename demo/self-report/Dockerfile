# Dependencies — resolved from the committed lockfile for reproducible builds.
FROM composer:2 AS deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress

FROM php:8.3-apache

# The app listens on 8080 (see fly.toml's internal_port; also the default
# for most container platforms); route every request that isn't an
# existing file to the front controller.
RUN sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf \
    && printf 'FallbackResource /index.php\nSetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1\n' > /etc/apache2/conf-available/fallback.conf \
    && a2enconf fallback

COPY --from=deps /app/vendor /var/www/html/vendor
COPY index.php /var/www/html/

EXPOSE 8080
