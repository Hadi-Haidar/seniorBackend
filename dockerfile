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

# Copy dependency files first (for better caching)
COPY composer.json composer.lock package.json package-lock.json ./

# Copy essential Laravel files needed for composer install
COPY artisan ./
COPY bootstrap/ ./bootstrap/
COPY config/ ./config/
COPY database/ ./database/

# Copy or create .env file BEFORE composer install (package discovery needs it)
COPY .env.example .env 2>/dev/null || echo "No .env.example found"
RUN if [ ! -f .env ]; then \
        echo "APP_NAME=Laravel" > .env && \
        echo "APP_ENV=production" >> .env && \
        echo "APP_KEY=" >> .env && \
        echo "APP_DEBUG=false" >> .env && \
        echo "APP_URL=http://localhost" >> .env && \
        echo "DB_CONNECTION=mysql" >> .env; \
    fi

# Create necessary directories
RUN mkdir -p storage/app storage/framework storage/logs bootstrap/cache

# Clear composer cache and install dependencies
RUN composer clear-cache

# Install PHP dependencies with fallback
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts || \
    (echo "Trying with scripts..." && composer install --no-dev --optimize-autoloader --no-interaction) || \
    (echo "Trying without optimization..." && composer install --no-dev --no-interaction)

# Install Node.js dependencies  
RUN npm ci --only=production

# Copy the rest of the application
COPY . .

# Ensure .env file is properly set up (overwrite if needed)
RUN if [ -f .env.example ] && [ ! -s .env ]; then \
        cp .env.example .env; \
    fi

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache \
    && find /var/www/html -type f -name "*.php" -exec chmod 644 {} \; \
    && find /var/www/html -type d -exec chmod 755 {} \;

# Set SSL certificate permissions if it exists (no copying needed since we already copied everything)
RUN if [ -f ssl/isrgrootx.pem ]; then \
        chmod 644 ssl/isrgrootx.pem && \
        chown www-data:www-data ssl/isrgrootx.pem; \
    fi

# Build frontend assets
RUN npm run build || echo "No build script found or build failed, continuing..."

# Laravel optimizations
RUN php artisan key:generate --force \
    && php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache

# Final permission fix after all operations
RUN chown -R www-data:www-data /var/www/html

# Expose port 80
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]