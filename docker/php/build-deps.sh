#!/bin/sh
#
# MuuCmf T6 镜像构建公共步骤（Dockerfile 与 Dockerfile.worker 共用）
#
# 之所以抽出脚本：两个 Dockerfile 的 apk 包清单 / PHP 扩展清单 / 运行时目录
# 属主处理完全重复，此前已经出现过「app 装了 xsl、worker 漏装」这类漂移。
#
# 用法：
#   sh build-deps.sh extensions [额外的 apk 包...]   编译期：装 apk 依赖 + PHP 扩展
#   sh build-deps.sh runtime                        运行时：修正目录权限与属主
#
set -e

MODE=${1:-extensions}
if [ $# -gt 0 ]; then
    shift
fi

install_extensions() {
    EXTRA_APK="$*"

    # shellcheck disable=SC2086
    apk add --no-cache \
        git \
        unzip \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libxml2-dev \
        libxslt-dev \
        icu-dev \
        oniguruma-dev \
        autoconf \
        g++ \
        make \
        $EXTRA_APK

    docker-php-ext-configure gd --with-freetype --with-jpeg

    docker-php-ext-install -j"$(nproc)" \
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
        zip

    pecl install redis
    docker-php-ext-enable redis

    rm -rf /tmp/pear
    apk del --purge autoconf g++ make
}

setup_runtime() {
    chown -R www-data:www-data /var/www/html
    chmod -R 755 /var/www/html
    mkdir -p /var/www/html/runtime
    mkdir -p /var/www/html/public/attachment
    chown -R www-data:www-data /var/www/html/runtime
    chown -R www-data:www-data /var/www/html/public/attachment
}

case "$MODE" in
    extensions)
        install_extensions "$@"
        ;;
    runtime)
        setup_runtime
        ;;
    *)
        echo "未知模式: ${MODE}（可用：extensions | runtime）" >&2
        exit 1
        ;;
esac