FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libzip-dev \
    libicu-dev \
    zip \
    unzip \
    curl \
    git \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo pdo_mysql mysqli intl zip

RUN a2enmod rewrite

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Replace default Apache vhost with our own (sets DocumentRoot + SetEnv CI_ENVIRONMENT)
COPY docker/app/000-default.conf /etc/apache2/sites-available/000-default.conf

COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

COPY composer.json composer.lock* ./
RUN composer install --optimize-autoloader --no-interaction

COPY . .

RUN chown -R www-data:www-data /var/www/html/writable 2>/dev/null || true

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
