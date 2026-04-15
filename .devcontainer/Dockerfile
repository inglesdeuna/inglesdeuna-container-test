FROM mcr.microsoft.com/devcontainers/php:1-8.3-bullseye

RUN apt-get update && \
    apt-get install -y default-mysql-client && \
    docker-php-ext-install pdo pdo_mysql
