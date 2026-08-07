FROM php:8-fpm-alpine
RUN apk add --no-cache nginx curl
COPY index.html /var/www/html/index.html
COPY proxy.php /var/www/html/proxy.php
RUN printf '%s\n' \
    'server {' \
    '    listen 80;' \
    '    root /var/www/html;' \
    '    index index.php index.html;' \
    '    location / {' \
    '        try_files $uri $uri/ /index.html;' \
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
