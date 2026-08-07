FROM php:8-cli-alpine
WORKDIR /var/www/html

# Install required PHP extensions
RUN docker-php-ext-install pdo pdo_sqlite

# Copy all application files
COPY . .

EXPOSE 3000

# Use router.php for clean URLs
CMD ["php", "-S", "0.0.0.0:3000", "router.php"]
