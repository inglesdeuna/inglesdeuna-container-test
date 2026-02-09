FROM php:8.2-apache

# =============================
# INSTALAR DEPENDENCIAS POSTGRES
# =============================
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql

# =============================
# (Opcional) Mantener MySQL si quieres compatibilidad
# =============================
RUN docker-php-ext-install mysqli pdo_mysql

# =============================
# COPIAR PROYECTO
# =============================
COPY . /var/www/html/

# =============================
# PERMISOS
# =============================
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80
