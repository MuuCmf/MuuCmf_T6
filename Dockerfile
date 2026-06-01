FROM composer:2.5 AS composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-dev --no-autoloader --no-scripts --ignore-platform-req=ext-*

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

RUN apk add --no-cache \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libxml2-dev \
    icu-dev \
    oniguruma-dev \
    libxslt-dev \
    autoconf \
    g++ \
    make \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        mysqli \
        opcache \
        pdo \
        pdo_mysql \
        xml \
        xsl \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear \
    && apk del --purge autoconf g++ make

COPY --from=composer /app /var/www/html

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/runtime \
    && chown -R www-data:www-data /var/www/html/runtime \
    && chown -R www-data:www-data /var/www/html/public/attachment

COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

RUN rm -rf /var/www/html/.git \
    && rm -rf /var/www/html/node_modules \
    && rm -rf /var/www/html/tests

EXPOSE 9000

CMD ["php-fpm"]
