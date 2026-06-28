# syntax=docker/dockerfile:1.7
ARG APP_USER=ipro
ARG APP_UID=1000

FROM php:8.4-fpm-alpine AS base

ARG APP_USER
ARG APP_UID

# ── System deps + build tools + PHP extensions (SATU layer, LEAN) ──────────────
# Dibuat ramping agar build cepat & andal:
#   • TANPA chromium  → PDF Browsershot bisa ditambah belakangan (hemat ~150 paket apk).
#   • TANPA swoole/Octane → ipro-fo diserve via nginx + php-fpm (CMD php-fpm).
#   • TANPA ext redis (phpredis) → ipro-fo pakai REDIS_CLIENT=predis (pure-PHP).
# Build tools + index apk + linux-headers digabung satu RUN supaya
# docker-php-ext-install tak gagal "no such package: gcc". linux-headers wajib utk
# extension `sockets` (<linux/sock_diag.h>).
RUN apk add --no-cache \
        bash git curl unzip \
        autoconf dpkg dpkg-dev file g++ gcc libc-dev make pkgconf re2c linux-headers \
        libpng-dev libjpeg-turbo-dev freetype-dev libzip-dev oniguruma-dev \
        icu-dev libxml2-dev openssl-dev \
        mariadb-client nodejs npm supervisor tzdata \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 \
        pdo pdo_mysql bcmath gd intl opcache pcntl zip mbstring exif sockets \
    && cp /usr/share/zoneinfo/Asia/Jakarta /etc/localtime && echo "Asia/Jakarta" > /etc/timezone

# ── Composer ─────────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── User ─────────────────────────────────────────────────────────────────────
RUN adduser -D -u ${APP_UID} ${APP_USER} \
    && mkdir -p /var/www/html \
    && chown -R ${APP_USER}:${APP_USER} /var/www/html

WORKDIR /var/www/html
USER ${APP_USER}

EXPOSE 9000 8000

CMD ["php-fpm"]
