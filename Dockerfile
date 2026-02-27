FROM php:8.2-apache

# =============================
# INSTALAR DEPENDENCIAS SISTEMA
# =============================
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && docker-php-ext-enable pdo_pgsql

# =============================
# INSTALAR COMPOSER
# =============================
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# =============================
# CONFIGURAR APACHE
# =============================
RUN a2enmod rewrite

# =============================
# COPIAR PROYECTO
# =============================
COPY . /var/www/html/
WORKDIR /var/www/html

# =============================
# INSTALAR DEPENDENCIAS PHP
# =============================
RUN composer install --no-dev --optimize-autoloader || true

# =============================
# PERMISOS
# =============================
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80
