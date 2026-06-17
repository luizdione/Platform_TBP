# ============================================================
# Dockerfile — Apache + PHP 8.2 (mesma stack do Hostinger)
# ============================================================
FROM php:8.2-apache

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev unzip default-mysql-client \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev \
        libonig-dev libxml2-dev curl ca-certificates \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo pdo_mysql mysqli mbstring zip gd opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# php.ini de desenvolvimento (mostrar erros)
RUN cp /usr/local/etc/php/php.ini-development /usr/local/etc/php/php.ini \
    && sed -i 's/^display_errors = .*/display_errors = On/' /usr/local/etc/php/php.ini \
    && sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 64M/' /usr/local/etc/php/php.ini \
    && sed -i 's/^post_max_size = .*/post_max_size = 64M/' /usr/local/etc/php/php.ini \
    && sed -i 's/^memory_limit = .*/memory_limit = 512M/' /usr/local/etc/php/php.ini

WORKDIR /var/www/html
EXPOSE 80
