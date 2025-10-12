# ---------- Base: PHP 8.3 + Apache ----------
FROM php:8.3-apache

# System deps (kun det vi trenger for denne appen)
RUN apt-get update && apt-get install -y --no-install-recommends \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libicu-dev \
    unzip \
    git \
    tzdata \
 && rm -rf /var/lib/apt/lists/*

# PHP-extensions
# - GD for intervention/image (jpeg + freetype)
# - intl, zip, exif, pdo_mysql
RUN docker-php-ext-configure gd --with-jpeg --with-freetype \
 && docker-php-ext-install -j$(nproc) \
    intl \
    zip \
    exif \
    gd \
    pdo_mysql

# (Valgfritt) WebP-støtte i GD:
# RUN apt-get update && apt-get install -y --no-install-recommends libwebp-dev \
#  && rm -rf /var/lib/apt/lists/* \
#  && docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
#  && docker-php-ext-install -j$(nproc) gd

# ---------- Timezone ----------
ENV TZ=Europe/Oslo
RUN echo "date.timezone = Europe/Oslo" > /usr/local/etc/php/conf.d/timezone.ini

# ---------- Apache ----------
# VHost med public/ som DocumentRoot, + mod_rewrite
RUN a2enmod rewrite && \
    printf '%s\n' \
      "ServerName localhost" \
      "<VirtualHost *:80>" \
      "    ServerAdmin webmaster@localhost" \
      "    DocumentRoot /var/www/html/public" \
      "    <Directory /var/www/html/public>" \
      "        Options -Indexes +FollowSymLinks" \
      "        AllowOverride All" \
      "        Require all granted" \
      "    </Directory>" \
      "    ErrorLog /proc/self/fd/2" \
      "    CustomLog /proc/self/fd/1 combined" \
      "</VirtualHost>" \
    > /etc/apache2/sites-available/000-default.conf

# ---------- Composer ----------
# Bruk Composer fra offisiell image for rask install
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# ---------- App kode ----------
WORKDIR /var/www/html

# Kopier kun composer-filer først for cached layer på deps
COPY ./src/composer.json ./composer.json
# (kopier også lock hvis du har)
# COPY ./src/composer.lock ./composer.lock

# Installer prod-avhengigheter
RUN composer install --no-interaction --no-plugins --no-scripts --no-dev --prefer-dist

# Kopier resten av koden
COPY ./src /var/www/html

# Sikre riktig eierskap
RUN chown -R www-data:www-data /var/www/html

# Opprett skrivbare mapper (bilder/opplastinger)
RUN mkdir -p /var/www/html/public/upload \
    && mkdir -p /var/www/html/public/data \
    && chown -R www-data:www-data /var/www/html/public/upload /var/www/html/public/data \
    && chmod -R 775 /var/www/html/public/upload /var/www/html/public/data

# PHP error-logg til stderr, noen fornuftige grenser for opplasting
RUN { \
      echo "log_errors=On"; \
      echo "error_reporting=E_ALL"; \
      echo "display_errors=Off"; \
      echo "error_log=/dev/stderr"; \
    } > /usr/local/etc/php/conf.d/error.ini \
 && { \
      echo "file_uploads=On"; \
      echo "upload_max_filesize=64M"; \
      echo "post_max_size=64M"; \
      echo "memory_limit=512M"; \
      echo "max_execution_time=60"; \
    } > /usr/local/etc/php/conf.d/uploads.ini

# Eksponér 80 (default i php:apache)
# EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
