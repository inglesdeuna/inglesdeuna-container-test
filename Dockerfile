FROM php:8.2-apache

# =============================
# INSTALAR DEPENDENCIAS POSTGRES
# =============================
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    git \
    curl \
    && docker-php-ext-install pdo pdo_pgsql pgsql

# =============================
# MYSQL (opcional)
# =============================
RUN docker-php-ext-install mysqli pdo_mysql

# =============================
# INSTALAR COMPOSER
# =============================
RUN curl -sS https://getcomposer.org/installer | php \
    && mv composer.phar /usr/local/bin/composer

# =============================
# COPIAR PROYECTO
# =============================
COPY . /var/www/html/
WORKDIR /var/www/html

# =============================
# INSTALAR CLOUDINARY
# =============================
RUN composer require cloudinary/cloudinary_php

# =============================
# PERMISOS
# =============================
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80
