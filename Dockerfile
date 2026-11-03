FROM node:22-alpine AS frontend
WORKDIR /frontend
COPY frontend/package*.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

FROM php:8.3-fpm-alpine
RUN apk add --no-cache icu-dev libpq-dev libzip-dev linux-headers $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql intl opcache zip \
    && pecl install redis && docker-php-ext-enable redis
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-autoloader
COPY . .
COPY .env.example .env
COPY --from=frontend /public/app ./public/app
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts && mkdir -p var/cache var/log \
    && APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear \
    && chown -R www-data:www-data var
CMD ["php-fpm"]
