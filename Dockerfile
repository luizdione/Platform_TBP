# Use official PHP with Apache image
FROM php:8.2-apache

# Install PHP extensions needed by the app
RUN docker-php-ext-install pdo pdo_mysql

# Enable Apache modules and allow .htaccess overrides
RUN a2enmod rewrite \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Set working directory
WORKDIR /var/www/html

# Copy application code
COPY . /var/www/html

# Expose port (Railway sets $PORT automatically, Apache uses 80 inside container)
EXPOSE 80

# No Dockerfile ou script de deploy
COPY apache-servername.conf /etc/apache2/conf-available/servername.conf
RUN a2enconf servername

# Start Apache server
CMD ["apache2-foreground"]
