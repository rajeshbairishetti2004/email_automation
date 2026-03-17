FROM php:8.3-apache

# Install the necessary PDO MySQL extension.
RUN docker-php-ext-install pdo_mysql

# Install system dependencies for zip and gd
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install zip gd

# Copy the entire contents of the directory into the web root
COPY . /var/www/html/ 

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN composer install --no-dev --optimize-autoloader

# Ensure uploads and attachments directories exist and are writable
RUN mkdir -p /var/www/html/uploads/attachments && \
    chown -R www-data:www-data /var/www/html/uploads && \
    chmod -R 775 /var/www/html/uploads

# Add ServerName to suppress the AH00558 warning
RUN echo "ServerName Localhost" >> /etc/apache2/apache2.conf

EXPOSE 80