FROM php:8.3-fpm-alpine

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

# ─── Install PHP extensions (tanpa sockets — tidak kompatibel WSL2) ──────────
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

# ─── Buat non-root user ───────────────────────────────────────────────────────
RUN addgroup -g ${GROUP_ID} -S minetra \
    && adduser -u ${USER_ID} -S minetra -G minetra \
    && adduser minetra www-data

WORKDIR /var/www/html

COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

RUN chown -R minetra:minetra /var/www/html

USER minetra

EXPOSE 9000

CMD ["php-fpm"]