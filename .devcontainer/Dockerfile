# Use Microsoft’s PHP Dev Container image
FROM mcr.microsoft.com/devcontainers/php:1-8.3-bullseye

# Install MySQL client and PHP extension
RUN rm -rf /etc/apt/sources.list.d/* \
    && apt-get update \
    && apt-get install -y default-mysql-client \
    && docker-php-ext-install pdo_mysql \
    && docker-php-ext-enable pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# Set working directory inside the container
WORKDIR /workspaces/inglesdeuna-container-test

# Copy your project files into the container
COPY . .

# Default command when container starts
CMD ["php", "-a"]
