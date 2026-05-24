# 1. Tambahkan "AS base" agar bisa dipanggil di stage berikutnya
FROM php:8.3-fpm-alpine AS base

ARG USER_ID=1000
ARG GROUP_ID=1000

# ─── Install system dependencies ─────────────────────────────────────────────
RUN apk add --no-cache \
    build-base \
    autoconf \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    oniguruma-dev \
    libxml2-dev \
    postgresql-dev \
    icu-dev \
    git \
    curl \
    unzip \
    zip \
    nodejs \
    npm \
    tzdata

# ─── Set timezone Jakarta ─────────────────────────────────────────────────────
RUN cp /usr/share/zoneinfo/Asia/Jakarta /etc/localtime \
    && echo "Asia/Jakarta" > /etc/timezone

# ─── Install PHP extensions ──────────────────────────────────────────────────
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        xml \
        intl \
        opcache

# ─── Install Redis extension via PECL ────────────────────────────────────────
RUN pecl install redis \
    && docker-php-ext-enable redis

# ─── Install Composer ────────────────────────────────────────────────────────
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# ─── Setup User ───────────────────────────────────────────────────────────────
# Di Alpine, php-fpm berjalan sebagai www-data (UID 82). 
# Kita selaraskan UID/GID agar sesuai dengan user di host (WSL/VPS).
RUN apk --no-cache add shadow \
    && usermod -u ${USER_ID} www-data \
    && groupmod -g ${GROUP_ID} www-data

WORKDIR /var/www/html
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

# ══════════════════════════════════════════════════════════════════════════════
# ─── PRODUCTION STAGE ─────────────────────────────────────────────────────────
# ══════════════════════════════════════════════════════════════════════════════
FROM base AS production

# Copy seluruh source code dan langsung ubah ownership ke www-data
COPY --chown=www-data:www-data . .

# Pindah ke user www-data untuk menginstal dependencies agar aman
USER www-data

# Install PHP dependencies (tanpa package dev)
RUN composer install --optimize-autoloader --no-dev --no-interaction

# Build frontend (Vite) lalu hapus folder node_modules agar image lebih ringan
RUN npm ci \
    && npm run build \
    && rm -rf node_modules

# Optimize Laravel (Cache)
RUN php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache

EXPOSE 9000
CMD ["php-fpm"]