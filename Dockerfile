FROM php:8.3-fpm

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql pcntl posix bcmath intl zip gd \
    && pecl install redis \
    && docker-php-ext-enable redis \

    && apt-get purge -y --auto-remove $PHPIZE_DEPS \

    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/custom.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/php/zz-azurion.conf /usr/local/etc/php-fpm.d/zz-azurion.conf

# Las dependencias se instalan antes de copiar el codigo para que un cambio
# en la aplicacion no invalide la capa de vendor.
COPY composer.json composer.lock /var/www/html/

# --no-dev es obligatorio: sin el, la imagen de produccion incluia phpunit,
# faker, sail y pail. --no-scripts evita ejecutar artisan sin el codigo.
RUN composer install --no-interaction --prefer-dist --no-dev --no-scripts --no-autoloader

COPY . /var/www/html

RUN composer dump-autoload --no-interaction --optimize --no-dev \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# El master de php-fpm sigue arrancando como root a proposito: es quien baja
# privilegios a www-data en cada worker, que es donde corre el codigo PHP.
CMD ["php-fpm"]
