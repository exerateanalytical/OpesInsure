FROM php:8.3-cli-alpine
RUN apk add --no-cache git unzip icu-dev libzip-dev postgresql-dev linux-headers \
 && docker-php-ext-install bcmath intl pcntl pdo_pgsql zip \
 && pecl install redis \
 && docker-php-ext-enable redis
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
EXPOSE 8080
