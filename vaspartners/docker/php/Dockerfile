FROM php:8.4-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl libpq-dev libzip-dev libicu-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j$(nproc) intl pdo_pgsql zip pcntl bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY docker/php/entrypoint.sh /usr/local/bin/vas-entrypoint
RUN chmod +x /usr/local/bin/vas-entrypoint

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/vas-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
