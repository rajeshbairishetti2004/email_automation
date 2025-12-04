# Dockerfile
FROM php:8.3-apache

# Install the necessary PDO MySQL extension.
RUN docker-php-ext-install pdo_mysql

# --- CRITICAL FIX: Install libzip-dev before installing the PHP zip extension ---
RUN apt-get update && apt-get install -y \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install zip
# -------------------------------------------------------------------------------

# Copy the entire contents of the 'Report/' directory into the web root
COPY . /var/www/html/ 

# Ensure uploads and attachments directories exist and are writable
RUN mkdir -p /var/www/html/uploads/attachments && \
    chown -R www-data:www-data /var/www/html/uploads && \
    chmod -R 775 /var/www/html/uploads

# Add ServerName to suppress the AH00558 warning
RUN echo "ServerName Localhost" >> /etc/apache2/apache2.conf

EXPOSE 80