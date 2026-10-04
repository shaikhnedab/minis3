# MiniS3 on Alpine: nginx + PHP-FPM in one small image.
# Single container (supervisor runs both processes), plain HTTP on :80 -
# terminate TLS in front (reverse proxy / load balancer) if you need it.
FROM php:8.3-fpm-alpine

RUN apk add --no-cache nginx supervisor sqlite-libs \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS sqlite-dev \
 && docker-php-ext-install -j"$(nproc)" pdo_sqlite \
 && docker-php-ext-enable pdo_sqlite \
 && apk del .build-deps

# PHP settings for large streamed uploads; compression stays off (see README).
COPY deploy/docker/php-minis3.ini /usr/local/etc/php/conf.d/minis3.ini
# nginx site (port 80) + supervisor to run nginx and php-fpm together.
COPY deploy/docker/nginx-minis3.conf /etc/nginx/http.d/minis3.conf
COPY deploy/docker/supervisord.conf /etc/supervisord.conf
RUN rm -f /etc/nginx/http.d/default.conf \
 && mkdir -p /run/nginx /var/log/supervisor

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html \
 && chmod 770 /var/www/html/data

EXPOSE 80
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
