# Use the official PHP 8.2 image with Apache
FROM php:8.2-apache

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    nodejs \
    npm \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy Apache configuration
COPY <<EOF /etc/apache2/sites-available/000-default.conf
<VirtualHost *:80>
    DocumentRoot /var/www/html/public
    
    <Directory /var/www/html/public>
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Allow composer to run as superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

# Copy all application files first
COPY . .

# Clear composer cache
RUN composer clear-cache

# Install PHP dependencies without scripts first, then with scripts
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts || \
    composer install --no-dev --optimize-autoloader --no-interaction

# Install Node.js dependencies
RUN npm ci --only=production

# Copy SSL certificate if it exists
RUN if [ -f ssl/isrgrootx.pem ]; then \
        mkdir -p /var/www/html/ssl && \
        cp ssl/isrgrootx.pem /var/www/html/ssl/; \
    fi

# Handle environment file
RUN if [ ! -f .env ]; then \
        if [ -f .env.example ]; then \
            cp .env.example .env; \
        else \
            echo "APP_NAME=Laravel" > .env && \
            echo "APP_ENV=production" >> .env && \
            echo "APP_KEY=" >> .env && \
            echo "APP_DEBUG=false" >> .env && \
            echo "APP_URL=http://localhost" >> .env; \
        fi \
    fi

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# Build frontend assets
RUN npm run build 2>/dev/null || echo "No build script found, skipping..."

# Generate application key and optimize for production
RUN php artisan key:generate --force || echo "Key generation failed"
RUN php artisan config:cache || echo "Config cache failed"
RUN php artisan route:cache || echo "Route cache failed"
RUN php artisan view:cache || echo "View cache failed"

# Expose port 80
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]