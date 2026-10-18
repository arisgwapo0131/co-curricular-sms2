FROM php:8.2-apache

# Set non-interactive environment for apt
ENV DEBIAN_FRONTEND=noninteractive

# Install required system dependencies, libraries & PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    libicu-dev \
    libcurl4-openssl-dev \
    zip \
    unzip \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j$(nproc) mysqli pdo pdo_mysql gd zip bcmath opcache mbstring exif intl \
    && a2enmod rewrite headers deflate expires \
    && sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && { \
        echo '<Directory /var/www/html>'; \
        echo '    Options -Indexes +FollowSymLinks'; \
        echo '    AllowOverride All'; \
        echo '    Require all granted'; \
        echo '</Directory>'; \
    } >> /etc/apache2/sites-available/000-default.conf \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Configure Production PHP Settings & Upload Limits
RUN { \
    echo 'upload_max_filesize = 64M'; \
    echo 'post_max_size = 64M'; \
    echo 'memory_limit = 256M'; \
    echo 'max_execution_time = 300'; \
    echo 'max_input_vars = 3000'; \
    echo 'date.timezone = Asia/Manila'; \
    echo 'session.save_path = /tmp'; \
    echo 'session.cookie_httponly = 1'; \
    echo 'display_errors = Off'; \
    echo 'log_errors = On'; \
    echo 'error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT'; \
} > /usr/local/etc/php/conf.d/custom-php.ini

# Set Apache DocumentRoot to the application directory
WORKDIR /var/www/html

# Force cache invalidation so Railway & container platforms build the latest code
ARG BUILD_VERSION=20261018_1545_railway_smsdb
ENV BUILD_VERSION=${BUILD_VERSION}

# Copy application files
COPY . /var/www/html

# Copy and setup entrypoint script for dynamic port binding & permissions
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
# Fix Windows CRLF line endings on the shell script & make executable
RUN sed -i -e 's/\r$//' /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

# Configure all upload directories and assign www-data ownership
RUN mkdir -p /var/www/html/app/uploads/achievements \
    && mkdir -p /var/www/html/app/uploads/applications \
    && mkdir -p /var/www/html/app/uploads/avatars \
    && mkdir -p /var/www/html/app/uploads/signatures \
    && mkdir -p /var/www/html/app/uploads/stamps \
    && mkdir -p /var/www/html/app/uploads/events \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/app/uploads

# Expose default HTTP ports
EXPOSE 80 8080

# Health check to ensure web server is answering requests
HEALTHCHECK --interval=10s --timeout=5s --start-period=5s --retries=5 \
    CMD curl -fsS http://127.0.0.1:${PORT:-80}/healthz.php || curl -fsS http://127.0.0.1:80/healthz.php || exit 1

# Use the flexible container entrypoint
ENTRYPOINT ["docker-entrypoint.sh"]

CMD ["apache2-foreground"]

