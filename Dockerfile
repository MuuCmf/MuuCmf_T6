FROM composer:2.5 AS composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-dev --no-autoloader --no-scripts

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative

FROM php:8.0-fpm-alpine

LABEL maintainer="MuuCmf <support@muucmf.cc>"
LABEL description="MuuCmf T6 Content Management System"

ARG BUILD_DATE
ARG VCS_REF

LABEL org.opencontainers.image.created=$BUILD_DATE
LABEL org.opencontainers.image.revision=$VCS_REF
LABEL org.opencontainers.image.title="MuuCmf T6"
LABEL org.opencontainers.image.description="Content Management System based on ThinkPHP 6"

WORKDIR /var/www/html

# 编译期依赖与 PHP 扩展清单由 docker/php/build-deps.sh 统一维护（与 worker 镜像共用）
COPY docker/php/build-deps.sh /tmp/build-deps.sh
RUN sh /tmp/build-deps.sh extensions

COPY --from=composer /app /var/www/html

RUN sh /tmp/build-deps.sh runtime

COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

RUN rm -rf /var/www/html/.git \
    && rm -rf /var/www/html/node_modules \
    && rm -rf /var/www/html/tests \
    && chmod +x /var/www/html/docker/php/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["/var/www/html/docker/php/entrypoint.sh"]
CMD ["php-fpm"]
