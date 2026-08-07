FROM php:8-cli-alpine
WORKDIR /var/www/html

# Enable built-in SQLite extension (no compile needed)
RUN echo "extension=pdo_sqlite" > /usr/local/etc/php/conf.d/docker-php-ext-pdo_sqlite.ini \
 && echo "extension=pdo" >> /usr/local/etc/php/conf.d/docker-php-ext-pdo_sqlite.ini

# Copy all application files
COPY . .

EXPOSE 3000

# Use router.php for clean URLs
CMD ["php", "-S", "0.0.0.0:3000", "router.php"]
