FROM nginx:alpine
COPY index.html /usr/share/nginx/html/index.html
RUN mkdir -p /tmp/nginx_cache
EXPOSE 80
