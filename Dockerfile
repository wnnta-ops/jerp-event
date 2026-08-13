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

# Workaround jaringan lokal: codeload.github.com kadang membalas HTTP/2 400 saat
# Composer mengunduh banyak paket PARALEL (rate-limit burst dari IP kantor). Batasi
# unduhan paralel ke 1 → andal. (Aman direvert bila build di jaringan lain/CI.)
ENV COMPOSER_MAX_PARALLEL_HTTP=1

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

# VITE_* ditanam ke bundle SAAT build (build-time). Tanpa nilai ini, window.Echo
# (real-time Reverb) & reCAPTCHA TIDAK aktif di image produksi. Di-passing sbg
# build args dari docker-compose.yml (lihat anchor x-vite-args).
ARG VITE_REVERB_APP_KEY=
ARG VITE_REVERB_HOST=localhost
ARG VITE_REVERB_PORT=8088
ARG VITE_REVERB_SCHEME=http
ARG VITE_RECAPTCHA_SITE_KEY=
ARG VITE_APP_NAME=J-ERP
ENV VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY \
    VITE_REVERB_HOST=$VITE_REVERB_HOST \
    VITE_REVERB_PORT=$VITE_REVERB_PORT \
    VITE_REVERB_SCHEME=$VITE_REVERB_SCHEME \
    VITE_RECAPTCHA_SITE_KEY=$VITE_RECAPTCHA_SITE_KEY \
    VITE_APP_NAME=$VITE_APP_NAME

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

# Chromium untuk Browsershot (PDF server-side) — OPSIONAL & BERAT (~200 paket,
# termasuk llvm/gtk/wayland). Default OFF supaya build cepat & tidak OOM di VPS
# kecil; cetak HTML tetap jalan tanpa ini. Aktifkan PDF saat butuh:
#   docker compose build --build-arg INSTALL_CHROMIUM=true app
# atau set INSTALL_CHROMIUM=true di .env lalu `docker compose up -d --build`.
ARG INSTALL_CHROMIUM=false
RUN if [ "$INSTALL_CHROMIUM" = "true" ]; then \
        apk add --no-cache chromium nss ttf-freefont font-noto-emoji \
        && rm -rf /var/cache/apk/*; \
    else \
        echo "[build] Chromium dilewati (INSTALL_CHROMIUM=false) — PDF Browsershot nonaktif, cetak HTML tetap jalan."; \
    fi

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

# Node.js + puppeteer utk Browsershot — Chromium SAJA tidak cukup: Browsershot
# menjalankan skrip Node yang me-require paket `puppeteer`. Dipasang di root app
# (/var/www/html/node_modules) agar resolvable dari vendor/spatie/browsershot.
# PUPPETEER_SKIP_DOWNLOAD sudah di-set → npm TIDAK mengunduh Chrome sendiri
# (pakai system chromium via PUPPETEER_EXECUTABLE_PATH).
# COBA-ULANG WAJIB: dari sebagian server, unduhan ke registry.npmjs.org sporadis
# ETIMEDOUT (jaringan keluar tidak stabil untuk transfer besar). Tanpa ini satu
# kegagalan mematikan SELURUH build image FO — padahal percobaan berikutnya
# biasanya lolos tanpa ada yang diubah. Selain loop, batas waktu & coba-ulang
# bawaan npm dilonggarkan supaya gangguan sesaat tidak langsung dianggap gagal.
# Subshell + `exit 0`: berhasil → keluar subshell 0 → pembersihan cache jalan.
# Kelima percobaan gagal → subshell `exit 1` → build BERHENTI. Jangan diganti
# `break`: image tanpa puppeteer tetap terbangun dan kegagalannya baru ketahuan
# saat PDF/lampiran SPRS diam-diam tak pernah terkirim.
RUN if [ "$INSTALL_CHROMIUM" = "true" ]; then \
        apk add --no-cache nodejs npm \
        && ( for i in 1 2 3 4 5; do \
               npm install puppeteer --omit=dev --no-audit --no-fund --no-save \
                 --fetch-retries=5 --fetch-retry-maxtimeout=120000 --fetch-timeout=600000 \
               && exit 0; \
               echo "[coba-ulang] npm install puppeteer gagal (percobaan \$i/5) — ulang dalam 15 detik"; \
               sleep 15; \
             done; echo "[coba-ulang] npm install puppeteer gagal 5x"; exit 1 ) \
        && rm -rf /root/.npm /var/cache/apk/*; \
    fi

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

# ───────────────────────────────────────────────────────────────────
# Stage: web — Nginx PRODUKSI dengan public/ + aset Vite di-bake dari image runtime.
# Dipakai oleh base docker-compose (prod). Dev pakai nginx:alpine + bind-mount source.
# ───────────────────────────────────────────────────────────────────
FROM nginx:1.27-alpine AS web

COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Salin public/ (index.php + aset Vite build) dari image aplikasi.
COPY --from=runtime /var/www/html/public /var/www/html/public

# Symlink /storage → upload publik (siteplan, dll). File-nya datang dari
# volume `storage` yang di-mount read-only ke container web saat runtime.
RUN ln -sf /var/www/html/storage/app/public /var/www/html/public/storage
