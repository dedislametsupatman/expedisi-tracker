FROM php:8-cli-alpine
WORKDIR /var/www/html
COPY index.html .
COPY proxy.php .
COPY server.php .
EXPOSE 3000
CMD ["php", "-S", "0.0.0.0:3000", "-t", "/var/www/html"]
