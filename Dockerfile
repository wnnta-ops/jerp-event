# syntax=docker/dockerfile:1.7
#
# J-ERP — Multi-stage Dockerfile (template untuk repo Laravel target)
# Stages: composer-deps -> node-build -> runtime
#
# Build:
#   docker build -t jerp/app:latest -f Dockerfile .
#
# Catatan: file ini adalah TEMPLATE. Pindahkan ke root repo Laravel target
# (atau ke docker/php/Dockerfile sesuai konvensi di PRD_cloning.md §13.9).

ARG PHP_VERSION=8.4
ARG NODE_VERSION=20
ARG COMPOSER_VERSION=2

# ─────────────────────────────────────────────────────────────────────
# Stage 1: composer-deps — PHP dependencies (production only)
# ─────────────────────────────────────────────────────────────────────
FROM composer:${COMPOSER_VERSION} AS composer-deps

WORKDIR /app

# Copy hanya manifest dulu agar layer composer cache-friendly
COPY composer.json composer.lock ./

# Install deps tanpa script/autoload (akan di-rebuild di runtime stage).
# --ignore-platform-reqs: composer image lacks intl/pcntl/gd/exif; runtime stage installs them.
RUN --mount=type=cache,target=/tmp/composer-cache \
    composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader \
        --ignore-platform-reqs

# Copy seluruh source untuk dump autoload (artisan tidak dipanggil di sini —
# itu butuh full PHP runtime + ext, dilakukan di runtime stage)
COPY . .

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# ─────────────────────────────────────────────────────────────────────
# Stage 2: node-build — Vite production bundle
# ─────────────────────────────────────────────────────────────────────
FROM node:${NODE_VERSION}-alpine AS node-build

WORKDIR /app

COPY package.json package-lock.json* ./

RUN --mount=type=cache,target=/root/.npm \
    npm ci --no-audit --no-fund

# Source yang dibutuhkan Vite
COPY resources/ resources/
COPY public/ public/
COPY vite.config.* tailwind.config.* postcss.config.* tsconfig.json* ./

RUN npm run build

# ─────────────────────────────────────────────────────────────────────
# Stage 3: runtime — PHP-FPM + app code + assets
# ─────────────────────────────────────────────────────────────────────
FROM php:${PHP_VERSION}-fpm-alpine AS runtime

# OCI labels
ARG APP_VERSION=dev
ARG BUILD_DATE
ARG VCS_REF
LABEL org.opencontainers.image.title="J-ERP" \
      org.opencontainers.image.description="J-ERP — Property Developer ERP (eProperty clone)" \
      org.opencontainers.image.source="https://github.com/your-org/jerp" \
      org.opencontainers.image.version="${APP_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.revision="${VCS_REF}" \
      org.opencontainers.image.licenses="Proprietary"

# OS deps + PHP build deps
RUN apk add --no-cache \
        tini \
        bash \
        curl \
        git \
        mysql-client \
        # MariaDB Connector/C plugins — menyediakan caching_sha2_password.so agar
        # mariadb-dump bisa autentikasi ke server MySQL 8.4 (backup database).
        mariadb-connector-c \
        icu-libs \
        libzip \
        libpng \
        libjpeg-turbo \
        freetype \
        oniguruma \
        # Browsershot (Puppeteer) butuh Chromium dari Alpine
        chromium \
        nss \
        freetype-dev \
        ttf-freefont \
        font-noto-emoji \
        # untuk supervisord (opsional kalau pakai multi-process di 1 container)
        supervisor \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        oniguruma-dev \
        linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        bcmath \
        intl \
        gd \
        zip \
        opcache \
        pcntl \
        exif \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/* /tmp/* /var/tmp/*

# Puppeteer/Browsershot: pakai system Chromium, bukan download
ENV PUPPETEER_SKIP_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium-browser

# PHP config
COPY docker/php/php.ini /usr/local/etc/php/conf.d/jerp.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-jerp.conf

WORKDIR /var/www/html

# Copy vendor dari composer stage
COPY --from=composer-deps --chown=www-data:www-data /app/vendor ./vendor

# Copy hasil build Vite
COPY --from=node-build --chown=www-data:www-data /app/public/build ./public/build

# Copy source code
COPY --chown=www-data:www-data . .

# Permission + storage link + Laravel optimize
RUN mkdir -p storage/framework/{sessions,views,cache,testing} \
             storage/logs \
             storage/app/{public,backups} \
             bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Entrypoint + healthcheck
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/php/healthcheck.sh /usr/local/bin/healthcheck.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/healthcheck.sh

# NOTE: image runs as root by default for dev (so entrypoint can fix bind-mount perms).
# Production override should drop privs via compose `user: www-data` or via a SUID-aware entrypoint.

EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD /usr/local/bin/healthcheck.sh

ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "--nodaemonize"]
