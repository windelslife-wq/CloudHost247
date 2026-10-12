# Real-WHMCS staging runtime only. Commercial core and ionCube loader are
# supplied by the operator, not downloaded/redistributed by this repository.
FROM php:8.2-apache
RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    libcurl4-openssl-dev libonig-dev libxml2-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mysqli intl mbstring curl gd zip soap bcmath \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
COPY ioncube/ioncube_loader_lin_8.2.so /usr/local/lib/php/extensions/ioncube_loader_lin_8.2.so
RUN echo 'zend_extension=/usr/local/lib/php/extensions/ioncube_loader_lin_8.2.so' > /usr/local/etc/php/conf.d/00-ioncube.ini
COPY php-staging.ini /usr/local/etc/php/conf.d/99-staging.ini
WORKDIR /var/www/html
