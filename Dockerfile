FROM php:8-cli-alpine
WORKDIR /var/www/html

# Install required extensions for SQLite and MySQL
RUN apk add --no-cache mariadb-dev sqlite-dev \
 && docker-php-ext-install pdo_mysql pdo_sqlite \
 && rm -rf /var/cache/apk/*

# Copy all application files
COPY . .

EXPOSE 80

# Use router.php for clean URLs
CMD ["php", "-S", "0.0.0.0:80", "router.php"]
