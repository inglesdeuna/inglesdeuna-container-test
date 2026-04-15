FROM mcr.microsoft.com/devcontainers/php:1-8.3-bullseye

RUN rm -rf /etc/apt/sources.list.d/* \
    && apt-get update \
    && apt-get install -y default-mysql-client \
    && docker-php-ext-install pdo_mysql