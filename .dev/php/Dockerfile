FROM php:8.4-fpm

# --- Dépendances système ---
# libicu-dev  : ext-intl (i18n FR/EN, indispensable à Symfony Translation)
# libzip-dev  : ext-zip (Composer)
# libonig-dev : ext-mbstring
# git/unzip   : Composer (installation depuis les sources / dist)
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
        libicu-dev \
        libzip-dev \
        libonig-dev \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        pdo_mysql \
        mbstring \
        zip \
        opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# --- Composer ---
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/app

EXPOSE 9000
CMD ["php-fpm"]
