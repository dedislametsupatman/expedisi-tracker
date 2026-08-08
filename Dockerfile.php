FROM php:8-fpm-alpine
RUN apk add --no-cache nginx curl mysql-client
WORKDIR /var/www/html

# Copy all files
COPY . .

# Enable MySQL and SQLite PDO extensions
RUN echo "extension=pdo_mysql" > /usr/local/etc/php/conf.d/docker-php-ext-pdo_mysql.ini \
    && echo "extension=pdo" >> /usr/local/etc/php/conf.d/docker-php-ext-pdo_mysql.ini \
    && echo "extension=pdo_sqlite" > /usr/local/etc/php/conf.d/docker-php-ext-pdo_sqlite.ini \
    && echo "extension=pdo" >> /usr/local/etc/php/conf.d/docker-php-ext-pdo_sqlite.ini

# PHP-FPM config
RUN printf '%s\n' \
    '[www]' \
    'listen = 127.0.0.1:9000' \
    'pm = dynamic' \
    'pm.max_children = 10' > /usr/local/etc/php-fpm.d/zz-docker.conf

# Nginx config - route /api/* to PHP, everything else to index.html
RUN printf '%s\n' \
    'server {' \
    '    listen 80;' \
    '    root /var/www/html;' \
    '    index index.php index.html;' \
    '    location / {' \
    '        try_files $uri $uri/ /index.html;' \
    '    }' \
    '    location ~ ^/api/ {' \
    '        try_files $uri $uri/ /router.php?$query_string;' \
    '    }' \
    '    location ~ \\.php$ {' \
    '        fastcgi_pass 127.0.0.1:9000;' \
    '        fastcgi_index index.php;' \
    '        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;' \
    '        include fastcgi_params;' \
    '    }' \
    '}' > /etc/nginx/http.d/default.conf

EXPOSE 80
CMD php-fpm -D && nginx -g "daemon off;"
